<?php

namespace App\Controller\Login;

use Symfony\Component\HttpFoundation\Response;
use App\Runtime\SafeFile;
use App\Content\HtmlContent;
use App\Content\ImageProcessor;
use App\Routing\GameRoute;
use App\Runtime\ProjectPaths;

/**
 * /news: paginated list, /news/{id} single article.
 */
final class NewsController extends AbstractLoginController
{
    private const PER_PAGE = 10;

    /**
     * News list (paged, pinned first) or, with id, one article; unknown ids end in a message. Request: id, p (page).
     * Article texts are rendered through the HTML sanitizer.
     */
    #[GameRoute('index', 'news', 'news')]
    #[\App\Routing\PublicCache(300)]
    #[GameRoute('index', 'news/{id}', 'news', 'article', requirements: ['id' => '\d+'])]
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $html = new HtmlContent();
        $now = $this->state->runtime->timestamp;

        $id = $this->input()->int('id');
        if ($id > 0) {
            $row = $this->db->selectSingle("SELECT * FROM %%NEWS%% WHERE id = :id AND status = 1 AND date <= :now;", [
                ':id' => $id,
                ':now' => $now,
            ]);
            if (empty($row)) {
                return $this->messageResponse($LNG['news_does_not_exist'], [['label' => $LNG['news_back'], 'url' => $this->router->generate('login_news_show')]]);
            }

            return $this->render('login/page.news.default.html.twig', [
                'article' => $this->present($row, $html) + ['text' => new \Twig\Markup($this->body($row, $html), 'UTF-8')],
            ]);
        }

        $total = (int) ($this->db->selectSingle("SELECT COUNT(*) AS n FROM %%NEWS%% WHERE status = 1 AND date <= :now;", [':now' => $now], 'n') ?? 0);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, $this->input()->int('p', 1)));

        $rows = $this->db->fetchAll(
            $this->db->qb()
                ->select('*')
                ->from($this->db->table('NEWS'))
                ->where('status = 1')
                ->andWhere('date <= :now')
                ->setParameter('now', $now)
                ->orderBy('pinned', 'DESC')
                ->addOrderBy('date', 'DESC')
                ->addOrderBy('id', 'DESC')
                ->setFirstResult(($page - 1) * self::PER_PAGE)
                ->setMaxResults(self::PER_PAGE),
        );
        $newsList = [];
        foreach ($rows as $row) {
            $item = $this->present($row, $html);
            $item['teaser'] = htmlspecialchars($row['summary'] !== '' ? $row['summary'] : $html->excerpt($this->body($row, $html), 240), ENT_QUOTES, 'UTF-8');
            $newsList[] = $item;
        }

        return $this->render('login/page.news.default.html.twig', [
            'newsList' => $newsList,
            'page'     => $page,
            'pages'    => $pages,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function body(array $row, HtmlContent $html): string
    {
        return $row['format'] === 'text' ? $html->fromPlainText($row['text']) : $html->rich($row['text']);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<mixed>
     */
    private function present(array $row, HtmlContent $html): array
    {
        $LNG = $this->state->LNG;

        return [
            'id'     => (int) $row['id'],
            'title'  => htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8'),
            'date'   => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $row['date']),
            'author' => htmlspecialchars($row['user'], ENT_QUOTES, 'UTF-8'),
            'pinned' => (int) $row['pinned'] === 1,
            'image'  => $this->image($row),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{src: string, srcset: ?string, width: int, height: int}|null srcset lists the width variants made on upload (null for older uploads: `app:images:variants uploads/news --widths=480,800,1200` backfills them)
     */
    private function image(array $row): ?array
    {
        $name = basename((string) ($row['image'] ?? ''));
        $path = ProjectPaths::ROOT . 'public/uploads/news/' . $name;
        if ($name === '' || !is_file($path) || ($size = SafeFile::imageSize($path)) === false) {
            return null;
        }

        return ['src' => 'uploads/news/' . rawurlencode($name), 'srcset' => ImageProcessor::srcset($path, 'uploads/news/' . rawurlencode($name), $size[0]), 'width' => $size[0], 'height' => $size[1]];
    }
}
