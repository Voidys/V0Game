//! Result JSON in the "Rust round log" format the combat report stores and renders.
//!
//! Written by hand: the key order is fixed (fleets and unit types in input order, statistics in report order, maps
//! sorted by id), whole numbers are written as integers, and no serde tree has to be built for big battles.

use std::fmt::Write;

use crate::engine::{BattleResult, Cell, RoundResult, Side, Store, TypeState, STATS, STAT_NAMES};

/// Appends a number: integers when whole (as the old engine wrote them), the shortest round-trip decimal otherwise.
fn number(out: &mut String, value: f64) {
    if !value.is_finite() {
        out.push('0');
    } else if value.fract() == 0.0 && value.abs() < 9.0e15 {
        let _ = write!(out, "{}", value as i64);
    } else {
        let _ = write!(out, "{value}");
    }
}

fn key(out: &mut String, text: &str) {
    out.push('"');
    for c in text.chars() {
        match c {
            '"' => out.push_str("\\\""),
            '\\' => out.push_str("\\\\"),
            c if (c as u32) < 0x20 => {
                let _ = write!(out, "\\u{:04x}", c as u32);
            }
            c => out.push(c),
        }
    }
    out.push('"');
}

/// `"Roundstats":{fleet:{unit:{attack,shield,hull,amount,lost,stunned,escape}}}` of one side.
fn roundstats(out: &mut String, side: &Side, states: &[Vec<TypeState>]) {
    out.push_str("\"Roundstats\":{");
    for (fi, fleet) in side.fleets.iter().enumerate() {
        if fi > 0 {
            out.push(',');
        }
        key(out, &fleet.key);
        out.push_str(":{");
        for (ti, ty) in fleet.types.iter().enumerate() {
            if ti > 0 {
                out.push(',');
            }
            let state = &states[fi][ti];
            let _ = write!(out, "\"{}\":{{\"attack\":", ty.id);
            number(out, state.attack);
            out.push_str(",\"shield\":");
            number(out, state.shield);
            out.push_str(",\"hull\":");
            number(out, state.hull);
            let _ = write!(out, ",\"amount\":{},\"lost\":{},\"stunned\":0,\"escape\":0}}", state.amount, state.lost);
        }
        out.push('}');
    }
    out.push('}');
}

/// `"Statistics":{name:{"maps":{from fleet:{to fleet:{from unit:{to unit:value}}}},"sum":total}}` of one side's shots.
fn statistics(out: &mut String, shooters: &Side, victims: &Side, store: Option<&Store>) {
    let entries = store.map(Store::entries).unwrap_or_default();
    // resolve slots to ids once
    let resolved: Vec<((usize, usize, u32, u32), Cell)> = entries
        .into_iter()
        .map(|(s, v, cell)| {
            let (sf, st) = shooters.locate(s);
            let (vf, vt) = victims.locate(v);
            ((sf, vf, shooters.fleets[sf].types[st].id, victims.fleets[vf].types[vt].id), cell)
        })
        .collect();

    out.push_str("\"Statistics\":{");
    for stat in 0..STATS {
        if stat > 0 {
            out.push(',');
        }
        let mut list: Vec<((usize, usize, u32, u32), f64)> = resolved.iter().filter(|(_, c)| c[stat] != 0.0).map(|(k, c)| (*k, c[stat])).collect();
        list.sort_by_key(|(k, _)| *k);
        let sum: f64 = list.iter().map(|(_, v)| *v).sum();

        let _ = write!(out, "\"{}\":{{\"maps\":{{", STAT_NAMES[stat]);
        let mut open: Option<(usize, usize, u32)> = None;
        let mut open_fleet: Option<usize> = None;
        for ((sf, vf, sid, vid), value) in &list {
            let same_from_fleet = open_fleet == Some(*sf);
            let same_to_fleet = same_from_fleet && open.map(|(_, ovf, _)| ovf) == Some(*vf);
            let same_from_unit = same_to_fleet && open.map(|(_, _, osid)| osid) == Some(*sid);
            if open.is_some() {
                if !same_from_unit {
                    out.push('}'); // close from unit
                    if !same_to_fleet {
                        out.push('}'); // close to fleet
                        if !same_from_fleet {
                            out.push('}'); // close from fleet
                        }
                    }
                    out.push(',');
                } else {
                    out.push(',');
                }
            }
            if !same_from_fleet {
                key(out, &shooters.fleets[*sf].key);
                out.push_str(":{");
            }
            if !same_to_fleet {
                key(out, &victims.fleets[*vf].key);
                out.push_str(":{");
            }
            if !same_from_unit {
                let _ = write!(out, "\"{sid}\":{{");
            }
            let _ = write!(out, "\"{vid}\":");
            number(out, *value);
            open = Some((*sf, *vf, *sid));
            open_fleet = Some(*sf);
        }
        if open.is_some() {
            out.push_str("}}}");
        }
        out.push_str("},\"sum\":");
        number(out, sum);
        out.push('}');
    }
    out.push('}');
}

fn round(out: &mut String, result: &BattleResult, round: &RoundResult) {
    out.push_str("{\"attacker\":{");
    roundstats(out, &result.attacker, &round.attacker);
    out.push(',');
    statistics(out, &result.attacker, &result.defender, round.attacker_stats.as_ref());
    out.push_str("},\"defender\":{");
    roundstats(out, &result.defender, &round.defender);
    out.push(',');
    statistics(out, &result.defender, &result.attacker, round.defender_stats.as_ref());
    out.push_str("}}");
}

/// The result JSON: `{"rounds":[...],"outcome":1|0|-1,"rngDraws":n,"seed":s,"engine":"..."}`.
pub fn to_json(result: &BattleResult) -> String {
    let mut out = String::with_capacity(4096);
    out.push_str("{\"rounds\":[");
    for (i, r) in result.rounds.iter().enumerate() {
        if i > 0 {
            out.push(',');
        }
        round(&mut out, result, r);
    }
    let _ = write!(
        out,
        "],\"outcome\":{},\"rngDraws\":{},\"seed\":{},\"engine\":\"battlerust {}\"}}",
        result.outcome,
        result.rng_draws,
        result.seed,
        env!("CARGO_PKG_VERSION")
    );

    out
}
