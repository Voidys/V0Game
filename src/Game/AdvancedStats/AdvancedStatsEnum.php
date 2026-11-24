<?php

namespace App\Game\AdvancedStats;

/**
 * All counter keys of the advanced statistics (the case name is the `statkey` stored in the DB). Besides the plain
 * cases there are per-element families (BUILD_<id>, LOST_<id>, REPAIRED_<id>, DESTROYED_<id>, FIRST_<id>,
 * EXPO_FIRST_<id>, EXPO_LOST_<id>, EXPO_FOUND_<id>) that the static getBuild()/getLost()/... helpers resolve from
 * an element id (null when no such case exists, which AdvancedStatsHandler treats as "do not count"). The `*_REAL`
 * cases (KBMETAL_REAL, KBCRYSTAL_REAL, LOSTUNITS_REAL, DESUNITS_REAL) are filled per destroyed unit by AdvancedStatsHandler;
 * their plain counterparts are summed per fight by the battle engines.
 */
enum AdvancedStatsEnum
{
    case BUILD_202;
    case BUILD_203;
    case BUILD_204;
    case BUILD_205;
    case BUILD_206;
    case BUILD_207;
    case BUILD_208;
    case BUILD_209;
    case BUILD_210;
    case BUILD_211;
    case BUILD_212;
    case BUILD_213;
    case BUILD_214;
    case BUILD_215;
    case BUILD_216;
    case BUILD_217;
    case BUILD_218;
    case BUILD_219;
    case BUILD_220;
    case BUILD_221;
    case BUILD_222;
    case BUILD_223;
    case BUILD_224;
    case BUILD_225;
    case BUILD_226;
    case BUILD_227;
    case BUILD_228;
    case BUILD_229;
    case BUILD_230;
    case BUILD_231;
    case BUILD_232;
    case BUILD_401;
    case BUILD_402;
    case BUILD_403;
    case BUILD_404;
    case BUILD_405;
    case BUILD_406;
    case BUILD_407;
    case BUILD_408;
    case BUILD_409;
    case BUILD_410;
    case BUILD_411;
    case BUILD_412;
    case BUILD_413;
    case BUILD_414;
    case BUILD_502;
    case BUILD_503;

    case LOST_202;
    case LOST_203;
    case LOST_204;
    case LOST_205;
    case LOST_206;
    case LOST_207;
    case LOST_208;
    case LOST_209;
    case LOST_210;
    case LOST_211;
    case LOST_212;
    case LOST_213;
    case LOST_214;
    case LOST_215;
    case LOST_216;
    case LOST_217;
    case LOST_218;
    case LOST_219;
    case LOST_220;
    case LOST_221;
    case LOST_222;
    case LOST_223;
    case LOST_224;
    case LOST_225;
    case LOST_226;
    case LOST_227;
    case LOST_228;
    case LOST_229;
    case LOST_230;
    case LOST_231;
    case LOST_232;
    case LOST_401;
    case LOST_402;
    case LOST_403;
    case LOST_404;
    case LOST_405;
    case LOST_406;
    case LOST_407;
    case LOST_408;
    case LOST_409;
    case LOST_410;
    case LOST_411;
    case LOST_412;
    case LOST_413;
    case LOST_414;
    case LOST_502;
    case LOST_503;

    case REPAIRED_202;
    case REPAIRED_203;
    case REPAIRED_204;
    case REPAIRED_205;
    case REPAIRED_206;
    case REPAIRED_207;
    case REPAIRED_208;
    case REPAIRED_209;
    case REPAIRED_210;
    case REPAIRED_211;
    case REPAIRED_212;
    case REPAIRED_213;
    case REPAIRED_214;
    case REPAIRED_215;
    case REPAIRED_216;
    case REPAIRED_217;
    case REPAIRED_218;
    case REPAIRED_219;
    case REPAIRED_220;
    case REPAIRED_221;
    case REPAIRED_222;
    case REPAIRED_223;
    case REPAIRED_224;
    case REPAIRED_225;
    case REPAIRED_226;
    case REPAIRED_227;
    case REPAIRED_228;
    case REPAIRED_229;
    case REPAIRED_230;
    case REPAIRED_231;
    case REPAIRED_232;
    case REPAIRED_401;
    case REPAIRED_402;
    case REPAIRED_403;
    case REPAIRED_404;
    case REPAIRED_405;
    case REPAIRED_406;
    case REPAIRED_407;
    case REPAIRED_408;
    case REPAIRED_409;
    case REPAIRED_410;
    case REPAIRED_411;
    case REPAIRED_412;
    case REPAIRED_413;
    case REPAIRED_414;

    case DESTROYED_202;
    case DESTROYED_203;
    case DESTROYED_204;
    case DESTROYED_205;
    case DESTROYED_206;
    case DESTROYED_207;
    case DESTROYED_208;
    case DESTROYED_209;
    case DESTROYED_210;
    case DESTROYED_211;
    case DESTROYED_212;
    case DESTROYED_213;
    case DESTROYED_214;
    case DESTROYED_215;
    case DESTROYED_216;
    case DESTROYED_217;
    case DESTROYED_218;
    case DESTROYED_219;
    case DESTROYED_220;
    case DESTROYED_221;
    case DESTROYED_222;
    case DESTROYED_223;
    case DESTROYED_224;
    case DESTROYED_225;
    case DESTROYED_226;
    case DESTROYED_227;
    case DESTROYED_228;
    case DESTROYED_229;
    case DESTROYED_230;
    case DESTROYED_231;
    case DESTROYED_232;
    case DESTROYED_401;
    case DESTROYED_402;
    case DESTROYED_403;
    case DESTROYED_404;
    case DESTROYED_405;
    case DESTROYED_406;
    case DESTROYED_407;
    case DESTROYED_408;
    case DESTROYED_409;
    case DESTROYED_410;
    case DESTROYED_411;
    case DESTROYED_412;
    case DESTROYED_413;
    case DESTROYED_414;
    case DESTROYED_502;
    case DESTROYED_503;

    case EXPO_COUNT;
    case EXPO_BLACK_HOLE;
    case EXPO_PIRATES_SMALL;
    case EXPO_PIRATES_MEDIUM;
    case EXPO_PIRATES_LARGE;
    case EXPO_ALIENS_SMALL;
    case EXPO_ALIENS_MEDIUM;
    case EXPO_ALIENS_LARGE;
    case EXPO_RES_SMALL;
    case EXPO_RES_MEDIUM;
    case EXPO_RES_LARGE;
    case EXPO_SHIPS_SMALL;
    case EXPO_SHIPS_MEDIUM;
    case EXPO_SHIPS_LARGE;
    case EXPO_SLOW;
    case EXPO_FAST;
    case EXPO_NOTHING;

    case EXPO_FOUND_202;
    case EXPO_FOUND_203;
    case EXPO_FOUND_204;
    case EXPO_FOUND_205;
    case EXPO_FOUND_206;
    case EXPO_FOUND_207;
    case EXPO_FOUND_208;
    case EXPO_FOUND_209;
    case EXPO_FOUND_210;
    case EXPO_FOUND_211;
    case EXPO_FOUND_212;
    case EXPO_FOUND_213;
    case EXPO_FOUND_214;
    case EXPO_FOUND_215;
    case EXPO_FOUND_216;
    case EXPO_FOUND_217;
    case EXPO_FOUND_218;
    case EXPO_FOUND_219;
    case EXPO_FOUND_220;
    case EXPO_FOUND_221;
    case EXPO_FOUND_222;
    case EXPO_FOUND_223;
    case EXPO_FOUND_224;
    case EXPO_FOUND_225;
    case EXPO_FOUND_226;
    case EXPO_FOUND_227;
    case EXPO_FOUND_228;
    case EXPO_FOUND_229;
    case EXPO_FOUND_230;
    case EXPO_FOUND_231;
    case EXPO_FOUND_232;

    case EXPO_FOUND_901;
    case EXPO_FOUND_902;
    case EXPO_FOUND_903;

    case EXPO_LOST_202;
    case EXPO_LOST_203;
    case EXPO_LOST_204;
    case EXPO_LOST_205;
    case EXPO_LOST_206;
    case EXPO_LOST_207;
    case EXPO_LOST_208;
    case EXPO_LOST_209;
    case EXPO_LOST_210;
    case EXPO_LOST_211;
    case EXPO_LOST_213;
    case EXPO_LOST_214;
    case EXPO_LOST_215;
    case EXPO_LOST_216;
    case EXPO_LOST_217;
    case EXPO_LOST_218;
    case EXPO_LOST_219;
    case EXPO_LOST_220;
    case EXPO_LOST_221;
    case EXPO_LOST_222;
    case EXPO_LOST_223;
    case EXPO_LOST_224;
    case EXPO_LOST_225;
    case EXPO_LOST_226;
    case EXPO_LOST_227;
    case EXPO_LOST_228;
    case EXPO_LOST_229;
    case EXPO_LOST_230;
    case EXPO_LOST_231;
    case EXPO_LOST_232;

    case PLANETS_CREATED;
    case PLANETS_DELETED;
    case MOONS_CREATED;
    case MOONS_DESTROYED;
    case MOONS_RECEIVED;
    case MOONS_LOST;
    case MOONS_DELETED;
    case FIRST_MOON_TRYS;
    case DESTROY_MOON_RIPS_LOST;
    case SET_SAIL_WINS;
    case ACS_WINS;

    case WONS;
    case LOOS;
    case DRAWS;
    case KBMETAL;
    case KBCRYSTAL;
    case LOSTUNITS;
    case DESUNITS;
    case KBMETAL_REAL;
    case KBCRYSTAL_REAL;
    case LOSTUNITS_REAL;
    case DESUNITS_REAL;
    case HONORPOINTS;
    case HONORABLE_FIGHTS;
    case DISHONORABLE_FIGHTS;
    case USED_FUEL;
    case GALAXY_SCROLL_SPENDING;
    case RAIDED_METAL;
    case RAIDED_CRYSTAL;
    case RAIDED_DEUTERIUM;
    case STOLEN_METAL;
    case STOLEN_CRYSTAL;
    case STOLEN_DEUTERIUM;
    case RECYCLED_METAL;
    case RECYCLED_CRYSTAL;

    case FIRST_VACATION;
    case FIRST_ATTACK;
    case FIRST_ATTACK_WIN;
    case FIRST_BIG_ATTACK_WIN;
    case FIRST_DEFENSE;
    case FIRST_DEFENSE_WIN;
    case FIRST_BIG_DEFENSE_WIN;
    case FIRST_COLONY;
    case FIRST_MOON;
    case FIRST_ESPIONAGE;
    case FIRST_RECYCLING;
    case FIRST_EXPEDITION;
    case FIRST_EXPO_BLACK_HOLE;
    case FIRST_EXPO_PIRATES_SMALL;
    case FIRST_EXPO_PIRATES_MEDIUM;
    case FIRST_EXPO_PIRATES_LARGE;
    case FIRST_EXPO_ALIENS_SMALL;
    case FIRST_EXPO_ALIENS_MEDIUM;
    case FIRST_EXPO_ALIENS_LARGE;
    case FIRST_EXPO_RES_SMALL;
    case FIRST_EXPO_RES_MEDIUM;
    case FIRST_EXPO_RES_LARGE;
    case FIRST_EXPO_SHIPS_SMALL;
    case FIRST_EXPO_SHIPS_MEDIUM;
    case FIRST_EXPO_SHIPS_LARGE;
    case FIRST_EXPO_SLOW;
    case FIRST_EXPO_FAST;
    case FIRST_EXPO_NOTHING;

    case FIRST_1;
    case FIRST_2;
    case FIRST_3;
    case FIRST_4;
    case FIRST_6;
    case FIRST_12;
    case FIRST_14;
    case FIRST_15;
    case FIRST_21;
    case FIRST_22;
    case FIRST_23;
    case FIRST_24;
    case FIRST_31;
    case FIRST_33;
    case FIRST_34;
    case FIRST_35;
    case FIRST_41;
    case FIRST_42;
    case FIRST_43;
    case FIRST_44;
    case FIRST_106;
    case FIRST_108;
    case FIRST_109;
    case FIRST_110;
    case FIRST_111;
    case FIRST_113;
    case FIRST_114;
    case FIRST_115;
    case FIRST_117;
    case FIRST_118;
    case FIRST_120;
    case FIRST_121;
    case FIRST_122;
    case FIRST_123;
    case FIRST_124;
    case FIRST_131;
    case FIRST_132;
    case FIRST_133;
    case FIRST_134;
    case FIRST_199;
    case FIRST_202;
    case FIRST_203;
    case FIRST_204;
    case FIRST_205;
    case FIRST_206;
    case FIRST_207;
    case FIRST_208;
    case FIRST_209;
    case FIRST_210;
    case FIRST_211;
    case FIRST_212;
    case FIRST_213;
    case FIRST_214;
    case FIRST_215;
    case FIRST_216;
    case FIRST_217;
    case FIRST_218;
    case FIRST_219;
    case FIRST_220;
    case FIRST_221;
    case FIRST_222;
    case FIRST_223;
    case FIRST_224;
    case FIRST_225;
    case FIRST_226;
    case FIRST_227;
    case FIRST_228;
    case FIRST_229;
    case FIRST_230;
    case FIRST_231;
    case FIRST_232;
    case FIRST_401;
    case FIRST_402;
    case FIRST_403;
    case FIRST_404;
    case FIRST_405;
    case FIRST_406;
    case FIRST_407;
    case FIRST_408;
    case FIRST_409;
    case FIRST_410;
    case FIRST_411;
    case FIRST_412;
    case FIRST_413;
    case FIRST_414;
    case FIRST_502;
    case FIRST_503;

    case EXPO_FIRST_202;
    case EXPO_FIRST_203;
    case EXPO_FIRST_204;
    case EXPO_FIRST_205;
    case EXPO_FIRST_206;
    case EXPO_FIRST_207;
    case EXPO_FIRST_208;
    case EXPO_FIRST_209;
    case EXPO_FIRST_210;
    case EXPO_FIRST_211;
    case EXPO_FIRST_212;
    case EXPO_FIRST_213;
    case EXPO_FIRST_214;
    case EXPO_FIRST_215;
    case EXPO_FIRST_216;
    case EXPO_FIRST_217;
    case EXPO_FIRST_218;
    case EXPO_FIRST_219;
    case EXPO_FIRST_220;
    case EXPO_FIRST_221;
    case EXPO_FIRST_222;
    case EXPO_FIRST_223;
    case EXPO_FIRST_224;
    case EXPO_FIRST_225;
    case EXPO_FIRST_226;
    case EXPO_FIRST_227;
    case EXPO_FIRST_228;
    case EXPO_FIRST_229;
    case EXPO_FIRST_230;
    case EXPO_FIRST_231;
    case EXPO_FIRST_232;

    case EXPO_FIRST_901;
    case EXPO_FIRST_902;
    case EXPO_FIRST_903;

    case OUTLAW_ACTIVATIONS;

    case ALLIANCE_PAGES_CREATED;

    private static function getEnumByName(string $enumName): ?self
    {
        try {
            return constant(self::class . "::$enumName");
        } catch (\Error $e) {
            return null;
        }
    }

    public static function getBuild(int $element): ?self
    {
        $enumName = "BUILD_" . $element;
        return self::getEnumByName($enumName);
    }

    public static function getLost(int $element): ?self
    {
        $enumName = "LOST_" . $element;
        return self::getEnumByName($enumName);
    }

    public static function getRepaired(int $element): ?self
    {
        $enumName = "REPAIRED_" . $element;
        return self::getEnumByName($enumName);
    }

    public static function getDestroyed(int $element): ?self
    {
        $enumName = "DESTROYED_" . $element;
        return self::getEnumByName($enumName);
    }

    public static function getFirst(int $element): ?self
    {
        $enumName = "FIRST_" . $element;
        return self::getEnumByName($enumName);
    }

    public static function getExpoFirst(int $element): ?self
    {
        $enumName = "EXPO_FIRST_" . $element;
        return self::getEnumByName($enumName);
    }

    public static function getExpoLost(int $element): ?self
    {
        $enumName = "EXPO_LOST_" . $element;
        return self::getEnumByName($enumName);
    }

    public static function getExpoFound(int $element): ?self
    {
        $enumName = "EXPO_FOUND_" . $element;
        return self::getEnumByName($enumName);
    }
}
