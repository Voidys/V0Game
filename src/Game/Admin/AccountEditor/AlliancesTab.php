<?php

namespace App\Game\Admin\AccountEditor;

use App\Game\Core\Log;
use Symfony\Component\HttpFoundation\Response;

/** Account editor tab "alliances": edits the alliance data: name, tag, texts, leader, members, deletion. */
class AlliancesTab extends AccountEditorTab
{
    /** Shows the form, or applies the posted changes (logged) and redirects with a message. */
    public function handle(): Response
    {
        if ($this->state->requestPost) {
            $this->save();
        }
        return $this->responder->render('admin/AccountEditorPageAlliance.html.twig');
    }

    /**
     * Saves the posted alliance changes (error message when the alliance does not exist): deletes the alliance when the
     * delete box is ticked, otherwise changes leader, removes a member and updates name, tag and texts, logs the change
     * (log type 6) and ends with a success message.
     */
    private function save(): never
    {
        $LNG = $this->state->LNG;
        $db = $this->db;
        $id = $this->input()->int('id');
        $delete = $this->input()->string('delete');

        $sql = "SELECT * FROM %%ALLIANCE%% WHERE `id` = :allyId AND `ally_universe` = :universe;";
        $beforeAlliance = $db->selectSingle($sql, [
            ':allyId'   => $id,
            ':universe' => $this->universe()->getEmulated(),
        ]);

        if (!isset($beforeAlliance)) {
            $this->responder->message($LNG['ad_ally_not_exist'], 'admin/accounts/edit/alliances');
        }

        // change plan: SQL set parts and parameters of the alliance update, the alliance row before and after (with the
        // changed members and, for a deletion, the removed related rows, for the log)
        $plan = ['values' => [], 'expr' => [], 'beforeAlliance' => $beforeAlliance];
        if ($delete == 'on') {
            $this->deleteAlliance($plan, $id);
        }

        $plan['afterAlliance'] = $plan['beforeAlliance'];
        $this->changeLeader($plan);
        $this->removeMember($plan, $id);
        $this->collectFieldChanges($plan);
        $this->writeAlliance($plan, $id);

        $this->responder->message($LNG['ad_ally_succes'], 'admin/accounts/edit/alliances');
    }

    /**
     * Deletes the alliance: releases its members, detaches/deletes its statistics rows, deletes ranks, join requests and
     * diplomacy entries, logs everything that was removed and ends with a success message.
     *
     * @param array<string, mixed> $plan change plan (the alliance row before the change is read from it)
     */
    private function deleteAlliance(array $plan, mixed $id): never
    {
        $LNG = $this->state->LNG;
        $db = $this->db;
        $this->releaseMembers($plan, $id);
        $this->detachStatistics($plan, $id);

        $sql = "DELETE FROM %%ALLIANCE%% WHERE `id` = :AllianceID;";
        $db->delete($sql, [
            ':AllianceID' => $id,
        ]);

        $db->delete("DELETE FROM %%ENG_ALLIANCE_GOALS%% WHERE `alliance_id` = :AllianceID;", [':AllianceID' => $id]);

        $sql = "DELETE FROM %%ALLIANCE_RANK%% WHERE `allianceID` = :AllianceID;";
        $db->delete($sql, [
            ':AllianceID' => $id,
        ]);

        $sql = "SELECT * FROM %%ALLIANCE_REQUEST%% WHERE `allianceId` = :AllianceID;";
        $beforeRequest = $db->selectSingle($sql, [
            ':AllianceID' => $id,
        ]);
        $sql = "DELETE FROM %%ALLIANCE_REQUEST%% WHERE `allianceId` = :AllianceID;";
        $db->delete($sql, [
            ':AllianceID' => $id,
        ]);
        $plan['beforeAlliance']['ALLIANCE_REQUEST'] = $beforeRequest;
        $plan['afterAlliance']['ALLIANCE_REQUEST'] = [];

        $sql = "SELECT * FROM %%DIPLO%% WHERE `owner_1` = :AllianceID OR `owner_2` = :AllianceID;";
        $beforeDiplos = $db->select($sql, [
            ':AllianceID' => $id,
        ]);
        $sql = "DELETE FROM %%DIPLO%% WHERE `owner_1` = :AllianceID OR `owner_2` = :AllianceID;";
        $db->delete($sql, [
            ':AllianceID' => $id,
        ]);
        $plan['beforeAlliance']['DIPLO'] = $beforeDiplos;
        $plan['afterAlliance']['DIPLO'] = [];

        $LOG = new Log($this->db, $this->configs, $this->state, 6);
        $LOG->target = $id;
        $LOG->universe = $this->universe()->getEmulated();
        $LOG->old = $plan['beforeAlliance'];
        $LOG->new = $plan['afterAlliance'];
        $LOG->save();

        $this->responder->message($LNG['ad_ally_succes'], 'admin/accounts/edit/alliances');
    }

    /**
     * Removes all members from the alliance (alliance id, rank and join time reset) and records them for the log.
     *
     * @param array<string, mixed> $plan change plan (modified)
     */
    private function releaseMembers(array &$plan, mixed $id): void
    {
        $db = $this->db;
        $sql = "SELECT `id`, `username`, `ally_id`, `ally_register_time`, `ally_rank_id`
        FROM %%USERS%% WHERE `ally_id` = :allyId AND `universe` = :universe;";
        $beforeMembers = $db->select($sql, [
            ':allyId'   => $id,
            ':universe' => $this->universe()->getEmulated(),
        ]);
        foreach ($beforeMembers as $member) {
            $afterMember = $member;
            $sql = "UPDATE %%USERS%% SET `ally_id` = '0', `ally_rank_id` = '0',
            `ally_register_time` = '0' WHERE `id` = :userId;";
            $db->update($sql, [
                ':userId' => $member['id'],
            ]);
            $afterMember['ally_id'] = 0;
            $afterMember['ally_rank_id'] = 0;
            $afterMember['ally_register_time'] = 0;
            $plan['beforeAlliance']['USERS'][$member['id']] = $member;
            $plan['afterAlliance']['USERS'][$member['id']] = $afterMember;
        }
    }

    /**
     * Detaches the members' statistics rows from the alliance (id_ally = 0) and deletes the alliance's own statistics row;
     * records both for the log.
     *
     * @param array<string, mixed> $plan change plan (modified)
     */
    private function detachStatistics(array &$plan, mixed $id): void
    {
        $db = $this->db;
        $sql = "SELECT `id_owner`, `id_ally`, `stat_type` FROM %%STATPOINTS%% WHERE `id_ally` = :AllianceID;";
        $beforeStats = $db->select($sql, [
            ':AllianceID' => $id,
        ]);
        foreach ($beforeStats as $stats) {
            $afterStats = $stats;
            $afterStats['id_ally'] = 0;
            $plan['beforeAlliance']['STATPOINTS'][1][] = $stats;
            $plan['afterAlliance']['STATPOINTS'][1][] = $afterStats;
        }
        $sql = "UPDATE %%STATPOINTS%% SET id_ally = '0' WHERE id_ally = :AllianceID;";
        $db->update($sql, [
            ':AllianceID' => $id,
        ]);

        $sql = "SELECT * FROM %%STATPOINTS%% WHERE `id_owner` = :AllianceID AND stat_type = 2;";
        $beforeStats = $db->selectSingle($sql, [
            ':AllianceID' => $id,
        ]);
        $sql = "DELETE FROM %%STATPOINTS%% WHERE id_owner = :AllianceID AND stat_type = 2;";
        $db->delete($sql, [
            ':AllianceID' => $id,
        ]);
        $plan['beforeAlliance']['STATPOINTS'][2] = $beforeStats;
        $plan['afterAlliance']['STATPOINTS'][2] = [];
    }

    /**
     * Makes another player the alliance leader (message when that player does not exist in the universe): the new leader
     * gets rank 0, `ally_owner` is updated with the alliance.
     *
     * @param array<string, mixed> $plan change plan (modified)
     */
    private function changeLeader(array &$plan): void
    {
        $LNG = $this->state->LNG;
        $db = $this->db;
        $changeleader = $this->input()->int('changeleader');

        if ($changeleader > 0) {
            $sql = "SELECT `id`, `username`, `ally_rank_id` FROM %%USERS%% WHERE `id` = :newLeaderId AND `universe` = :universe;";
            $beforeNewLeader = $db->selectSingle($sql, [
                ':newLeaderId' => $changeleader,
                ':universe'    => $this->universe()->getEmulated(),
            ]);
            $afterNewLeader = $beforeNewLeader;

            if (!isset($beforeNewLeader)) {
                $this->responder->message($LNG['ad_ally_not_exist2'], 'admin/accounts/edit/alliances');
            }

            $sql = "UPDATE %%USERS%% SET `ally_rank_id` = 0 WHERE `id` = :newLeaderId;";
            $db->update($sql, [
                ':newLeaderId' => $changeleader,
            ]);
            $afterNewLeader['ally_rank_id'] = 0;

            $plan['values']['ally_owner'] = $changeleader;
            $plan['afterAlliance']['ally_owner'] = $changeleader;
            $plan['beforeAlliance']['USERS'][$changeleader] = $beforeNewLeader;
            $plan['afterAlliance']['USERS'][$changeleader] = $afterNewLeader;
        }
    }

    /**
     * Removes a member from the alliance (message when that player is not a member) and counts the alliance one member
     * less.
     *
     * @param array<string, mixed> $plan change plan (modified)
     */
    private function removeMember(array &$plan, mixed $id): void
    {
        $LNG = $this->state->LNG;
        $db = $this->db;
        $delete_u = $this->input()->string('delete_u');

        if (!empty($delete_u)) {
            $sql = "SELECT `id`, `username`, `ally_id`, `ally_register_time`, `ally_rank_id`
            FROM %%USERS%% WHERE `id` = :userId AND `ally_id` = :allyId AND `universe` = :universe;";
            $beforeMember = $db->selectSingle($sql, [
                ':userId'   => $delete_u,
                ':allyId'   => $id,
                ':universe' => $this->universe()->getEmulated(),
            ]);
            $afterMember = $beforeMember;

            if (!isset($beforeMember)) {
                $this->responder->message($LNG['ad_ally_not_exist3'], 'admin/accounts/edit/alliances');
            }

            $sql = "UPDATE %%USERS%% SET `ally_id` = '0', `ally_rank_id` = '0', `ally_register_time` = '0'
            WHERE `id` = :userId AND `ally_id` = :allyId AND `universe` = :universe;";
            $db->update($sql, [
                ':userId'   => $delete_u,
                ':allyId'   => $id,
                ':universe' => $this->universe()->getEmulated(),
            ]);
            $afterMember['ally_id'] = 0;
            $afterMember['ally_rank_id'] = 0;
            $afterMember['ally_register_time'] = 0;

            $plan['expr']['ally_members'] = "`ally_members` - 1";
            $plan['afterAlliance']['ally_members'] -= 1;
            $plan['beforeAlliance']['USERS'][$delete_u] = $beforeMember;
            $plan['afterAlliance']['USERS'][$delete_u] = $afterMember;
        }
    }

    /**
     * Collects the changed texts of the form (name, tag, external/internal description, join request text).
     *
     * @param array<string, mixed> $plan change plan (modified)
     */
    private function collectFieldChanges(array &$plan): void
    {
        $name = $this->input()->string('name', '', UTF8_SUPPORT);
        $tag = $this->input()->string('tag', '', UTF8_SUPPORT);
        $externo = $this->input()->string('externo', '', true);
        $interno = $this->input()->string('interno', '', true);
        $solicitud = $this->input()->string('solicitud', '', true);

        if (!empty($name)) {
            $plan['values']['ally_name'] = $name;
            $plan['afterAlliance']['ally_name'] = $name;
        }

        if (!empty($tag)) {
            $plan['values']['ally_tag'] = $tag;
            $plan['afterAlliance']['ally_tag'] = $tag;
        }

        if (!empty($externo)) {
            $plan['values']['ally_description'] = $externo;
            $plan['afterAlliance']['ally_description'] = $externo;
        }

        if (!empty($interno)) {
            $plan['values']['ally_text'] = $interno;
            $plan['afterAlliance']['ally_text'] = $interno;
        }

        if (!empty($solicitud)) {
            $plan['values']['ally_request'] = $solicitud;
            $plan['afterAlliance']['ally_request'] = $solicitud;
        }
    }

    /**
     * Writes the collected alliance changes and the admin log entry.
     *
     * @param array<string, mixed> $plan change plan
     */
    private function writeAlliance(array $plan, mixed $id): void
    {
        $db = $this->db;
        $db->updateRows('ALLIANCE', $plan['values'], ['id' => $id, 'ally_universe' => $this->universe()->getEmulated()], $plan['expr']);

        $LOG = new Log($this->db, $this->configs, $this->state, 6);
        $LOG->target = $id;
        $LOG->universe = $this->universe()->getEmulated();
        $LOG->old = $plan['beforeAlliance'];
        $LOG->new = $plan['afterAlliance'];
        $LOG->save();
    }
}
