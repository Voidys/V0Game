<?php

namespace App\Game\Admin\AccountEditor;

use App\Game\Core\Log;
use App\Game\Engagement\Relics\RelicBonus;
use App\Game\Engagement\Relics\RelicCatalog;
use App\Game\Engagement\Relics\RelicInventory;
use Symfony\Component\HttpFoundation\Response;

/**
 * Account editor tab "relics": lists the relics and the dust of a player, grants a rolled relic (chosen slot, set and
 * rarity), removes a relic and sets the dust balance (support cases). Every change is written to the admin log.
 */
class RelicsTab extends AccountEditorTab
{
    /** Shows the player's relics (GET, optional id), or applies a grant / remove / dust change (POST). */
    public function handle(): Response
    {
        if ($this->state->requestPost) {
            $this->save();
        }

        $LNG = $this->state->LNG;
        $id = $this->input()->int('id');
        $universe = (int) $this->universe()->getEmulated();
        $config = (new RelicCatalog($this->db, $this->configs, $this->state))->config($universe);
        $inventory = $this->inventory();

        $relics = [];
        $dust = 0;
        if ($id > 0 && $this->playerExists($id)) {
            $dust = $inventory->dustOf($id);
            foreach ($inventory->relicsOf($id) as $row) {
                $relics[] = $this->row($row, $config);
            }
        }
        $sets = [];
        foreach ($config['sets'] as $code => $set) {
            $sets[$code] = RelicCatalog::setLabel($LNG, $config, $code);
        }
        $slots = [];
        foreach (RelicCatalog::SLOTS as $slot => $code) {
            $slots[$slot] = $LNG['rl_slot_' . $code];
        }
        $rarities = [];
        foreach (RelicCatalog::RARITIES as $rarity => $key) {
            $rarities[$rarity] = $LNG['rl_rarity_' . $key];
        }

        $this->responder->assign([
            'playerId' => $id,
            'relicDust' => $dust,
            'relicRows' => $relics,
            'relicSetOptions' => $sets,
            'relicSlotOptions' => $slots,
            'relicRarityOptions' => $rarities,
            'relicsOn' => (new RelicCatalog($this->db, $this->configs, $this->state))->enabled($universe),
        ]);

        return $this->responder->render('admin/AccountEditorPageRelics.html.twig');
    }

    private function inventory(): RelicInventory
    {
        return new RelicInventory($this->db, $this->configs, $this->state);
    }

    private function playerExists(int $id): bool
    {
        return !empty($this->db->selectSingle('SELECT `id` FROM %%USERS%% WHERE `id` = :id AND `universe` = :uni;', [':id' => $id, ':uni' => $this->universe()->getEmulated()], 'id'));
    }

    /**
     * One relic for the table.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function row(array $row, array $config): array
    {
        $LNG = $this->state->LNG;
        $subs = [];
        foreach ((array) json_decode((string) $row['subs'], true) as $sub) {
            $subs[] = $LNG['rl_stat_' . $sub['stat']] . ' ' . RelicCatalog::formatValue($sub['stat'], (float) $sub['value']);
        }

        return [
            'id' => (int) $row['id'],
            'slot' => $LNG['rl_slot_' . RelicCatalog::SLOTS[(int) $row['slot']]],
            'set' => RelicCatalog::setLabel($LNG, $config, (string) $row['set_code']),
            'rarity' => (int) $row['rarity'],
            'rarityLabel' => $LNG['rl_rarity_' . RelicCatalog::RARITIES[(int) $row['rarity']]],
            'main' => $LNG['rl_stat_' . $row['main_stat']] . ' ' . RelicCatalog::formatValue((string) $row['main_stat'], (float) $row['main_value']),
            'subs' => $subs,
            'planet' => $row['planet_id'] === null ? '' : $row['planet_name'] . ' [' . $row['planet_galaxy'] . ':' . $row['planet_system'] . ':' . $row['planet_pos'] . ']',
            'locked' => (int) $row['locked'] === 1,
            'source' => (string) $row['source'],
        ];
    }

    /**
     * Applies the posted action (`action` = grant, remove or dust) for the player, logs it and ends with a message.
     * Unknown players, relics or catalog entries end with an error message.
     */
    private function save(): never
    {
        $LNG = $this->state->LNG;
        $id = $this->input()->int('id');
        $action = (string) ($this->state->requestPost['action'] ?? '');
        $back = 'admin/accounts/edit/relics?id=' . $id;
        $universe = (int) $this->universe()->getEmulated();
        $now = $this->state->runtime->timestamp;
        if (!$this->playerExists($id)) {
            $this->responder->message($LNG['ad_rel_invalid'], $back, 3, true);
        }

        $inventory = $this->inventory();
        $old = [];
        $new = [];
        if ($action === 'grant') {
            $relic = $inventory->grant($id, $universe, $this->input()->int('slot'), $this->input()->string('set'), $this->input()->int('rarity'), $now);
            if ($relic === null) {
                $this->responder->message($LNG['ad_rel_invalid'], $back, 3, true);
            }
            $old = ['relic' => 0];
            $new = ['relic' => '#' . $relic['id']];
            $text = $LNG->format('ad_rel_granted', $relic['id']);
        } elseif ($action === 'remove') {
            $relicId = $this->input()->int('relic');
            if ($inventory->remove($id, $universe, $relicId, $now) !== 'ok') {
                $this->responder->message($LNG['ad_rel_invalid'], $back, 3, true);
            }
            $old = ['relic' => '#' . $relicId];
            $new = ['relic' => 0];
            $text = $LNG->format('ad_rel_removed', $relicId);
        } elseif ($action === 'dust') {
            $dust = max(0, $this->input()->int('dust'));
            $old = ['dust' => $inventory->dustOf($id)];
            $inventory->setDust($id, $universe, $dust, $now);
            $new = ['dust' => $dust];
            $text = $LNG->format('ad_rel_dust_set', $dust);
        } else {
            $this->responder->message($LNG['ad_rel_invalid'], $back, 3, true);
        }
        RelicBonus::flush();

        $LOG = new Log($this->db, $this->configs, $this->state, '1');
        $LOG->target = $id;
        $LOG->universe = $universe;
        $LOG->old = $old;
        $LOG->new = $new;
        $LOG->save();

        $this->responder->message($text, $back);
    }
}
