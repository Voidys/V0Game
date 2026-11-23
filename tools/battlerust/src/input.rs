//! Battle input as sent by `BattleEngineRust::createRustJson()`.

use serde::Deserialize;
use serde_json::Value;

/// Whole battle request.
#[derive(Debug, Deserialize)]
pub struct BattleInput {
    /// Maximum number of fights (PHP: `MAX_ATTACK_ROUNDS`, 6).
    #[serde(default = "default_rounds")]
    pub rounds: u32,
    /// Optional `mt_srand()` seed; the fight then consumes exactly the random stream of the PHP engine seeded alike.
    #[serde(default)]
    pub seed: Option<i64>,
    /// Refuse fights with more ships than this (memory guard, the caller falls back to the PHP engine). Default 60 million (16 bytes each, about 1 GB).
    #[serde(default, rename = "maxShips")]
    pub max_ships: Option<u64>,
    /// Attacking fleets in firing order.
    #[serde(default)]
    pub attacker: Vec<FleetInput>,
    /// Defending fleets in firing order.
    #[serde(default)]
    pub defender: Vec<FleetInput>,
}

fn default_rounds() -> u32 {
    6
}

/// One fleet: a name (the PHP fleet id, echoed back as key) and one entry per unit type.
#[derive(Debug, Deserialize)]
pub struct FleetInput {
    /// Fleet id, a JSON number or string.
    pub name: Value,
    /// Unit types in the order the PHP engine creates the ships.
    #[serde(default)]
    pub ships: Vec<ShipInput>,
}

/// One unit type of a fleet with its tech-adjusted values (the PHP engine's `att`, `shield`, initial `armor`).
#[derive(Debug, Deserialize)]
pub struct ShipInput {
    /// Unit id (202 small cargo, 401 rocket launcher, ...).
    pub shipid: u32,
    /// Attack per shot.
    pub attack: f64,
    /// Armor (hull) of one ship at full health.
    pub hull: f64,
    /// Shield of one ship (restored every round).
    pub shield: f64,
    /// Explosion threshold: a ship below this share of its initial armor may explode when hit; 0 = never (defenses).
    #[serde(default)]
    pub explode: f64,
    /// Number of ships.
    #[serde(default)]
    pub amount: u64,
    /// Rapid fire table: victim unit id -> N (the shooter fires again with probability (N-1)/N). A JSON object, or
    /// an empty array (PHP encodes an empty table either way).
    #[serde(default)]
    pub rapidfire: Value,
}

impl ShipInput {
    /// The rapid fire table as (victim id, N) pairs in input order.
    pub fn rapid_fire_table(&self) -> Vec<(u32, i64)> {
        let mut table = Vec::new();
        match &self.rapidfire {
            Value::Object(map) => {
                for (key, value) in map {
                    if let (Ok(id), Some(n)) = (key.parse::<u32>(), value.as_i64().or_else(|| value.as_f64().map(|f| f as i64))) {
                        table.push((id, n));
                    }
                }
            }
            Value::Array(list) => {
                for (id, value) in list.iter().enumerate() {
                    if let Some(n) = value.as_i64() {
                        table.push((id as u32, n));
                    }
                }
            }
            _ => {}
        }
        table
    }
}

impl FleetInput {
    /// The fleet name as a JSON object key.
    pub fn key(&self) -> String {
        match &self.name {
            Value::String(s) => s.clone(),
            other => other.to_string(),
        }
    }
}
