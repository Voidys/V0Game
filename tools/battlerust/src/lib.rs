//! battlerust: the V0Game battle engine as a C library.
//!
//! A port of the PHP engine `BattleEngineSteemNova` that reproduces its results bit for bit when given the same seed
//! (own implementation of PHP's `mt_rand`, see [`rng`]). The PHP side (`BattleEngineRust`) builds the battle as JSON,
//! the launcher `bin/battlerust` passes it to [`fight_battle_rounds`] and prints the returned JSON.
//!
//! C ABI:
//! * `char *fight_battle_rounds(const char *input_json)`: NUL-terminated input JSON in, newly allocated NUL-terminated
//!   result JSON out; NULL on error (message on stderr). The caller releases the result with `battlerust_free`.
//! * `void battlerust_free(char *result)`: frees a result of `fight_battle_rounds` (NULL is fine).
//! * `const char *battlerust_version(void)`: static version string.
//!
//! The input/output formats are documented in this crate (input.rs, output.rs).

pub mod engine;
pub mod input;
pub mod output;
pub mod rng;

use std::ffi::{c_char, CStr, CString};
use std::panic::catch_unwind;

/// Fights the battle given as JSON and returns the result JSON (see [`output::to_json`]).
pub fn fight_json(input_json: &str) -> Result<String, String> {
    let input: input::BattleInput = serde_json::from_str(input_json).map_err(|e| format!("invalid battle input: {e}"))?;
    let result = engine::run(&input)?;

    Ok(output::to_json(&result))
}

/// C entry point: see the crate docs. Never unwinds into the caller.
///
/// # Safety
/// `input_json` must be NULL or point to a NUL-terminated string.
#[no_mangle]
pub unsafe extern "C" fn fight_battle_rounds(input_json: *const c_char) -> *mut c_char {
    if input_json.is_null() {
        eprintln!("battlerust: no input");
        return std::ptr::null_mut();
    }
    let text = match CStr::from_ptr(input_json).to_str() {
        Ok(text) => text.to_owned(),
        Err(_) => {
            eprintln!("battlerust: input is not valid UTF-8");
            return std::ptr::null_mut();
        }
    };

    match catch_unwind(|| fight_json(&text)) {
        Ok(Ok(json)) => CString::new(json).map(CString::into_raw).unwrap_or(std::ptr::null_mut()),
        Ok(Err(message)) => {
            eprintln!("battlerust: {message}");
            std::ptr::null_mut()
        }
        Err(_) => {
            eprintln!("battlerust: internal error");
            std::ptr::null_mut()
        }
    }
}

/// Releases a string returned by [`fight_battle_rounds`].
///
/// # Safety
/// `result` must be NULL or a pointer returned by `fight_battle_rounds` that was not freed yet.
#[no_mangle]
pub unsafe extern "C" fn battlerust_free(result: *mut c_char) {
    if !result.is_null() {
        drop(CString::from_raw(result));
    }
}

/// Version of the library, e.g. "1.0.0".
#[no_mangle]
pub extern "C" fn battlerust_version() -> *const c_char {
    concat!(env!("CARGO_PKG_VERSION"), "\0").as_ptr().cast()
}
