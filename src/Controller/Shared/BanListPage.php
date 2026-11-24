<?php

namespace App\Controller\Shared;

/** Ban list data shared by the public and the in-game ban list (newest first, paged; request: side). */
trait BanListPage
{
    /**
     * @param string $timezone timezone of the shown start and end of the bans
     * @return array<string, mixed> template variables: banList, banCount, page, maxPage
     */
    protected function banListData(string $timezone): array
    {
        $LNG = $this->state->LNG;
        $universe = $this->universe()->current();

        $banCount = $this->db->selectSingle('SELECT COUNT(*) as count FROM %%USERS_BANNED%% WHERE universe = :universe;', [':universe' => $universe], 'count');
        $maxPage = ceil($banCount / BANNED_USERS_PER_PAGE);
        $page = max(1, min($this->input()->int('side', 1), $maxPage));

        $banResult = $this->db->select('SELECT * FROM %%USERS_BANNED%% WHERE universe = :universe ORDER BY time DESC LIMIT :offset, :limit;', [
            ':universe' => $universe,
            ':offset' => ($page - 1) * BANNED_USERS_PER_PAGE,
            ':limit' => BANNED_USERS_PER_PAGE,
        ]);

        $banList = [];
        foreach ($banResult as $banRow) {
            $banList[] = [
                'player' => $banRow['who'],
                'playerId' => (int) $banRow['user_id'],
                'theme' => $banRow['theme'],
                'from' => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $banRow['time'], $timezone),
                'to' => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $banRow['longer'], $timezone),
                'admin' => $banRow['author'],
                'mail' => $banRow['email'],
                'info' => $LNG->format('bn_writemail', $banRow['author']),
            ];
        }

        return ['banList' => $banList, 'banCount' => $banCount, 'page' => $page, 'maxPage' => $maxPage];
    }
}
