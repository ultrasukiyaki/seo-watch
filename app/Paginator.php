<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

final class Paginator
{
    /** @var list<int> */
    private const DEFAULT_ALLOWED_PER_PAGE = [20, 25, 50, 100];

    /**
     * @param list<mixed> $items
     * @param list<int> $allowedPerPage
     * @return array{rows:list<mixed>,page:int,per_page:int,total:int,total_pages:int,has_previous:bool,has_next:bool,from:int,to:int}
     */
    public static function slice(
        array $items,
        int $page = 1,
        int $perPage = 20,
        array $allowedPerPage = self::DEFAULT_ALLOWED_PER_PAGE
    ): array {
        $perPage = self::normalizePerPage($perPage, $allowedPerPage);
        $total = count($items);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;
        $rows = array_values(array_slice($items, $offset, $perPage));
        $from = $total > 0 ? $offset + 1 : 0;
        $to = $total > 0 ? min($offset + count($rows), $total) : 0;

        return [
            'rows' => $rows,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'has_previous' => $page > 1,
            'has_next' => $page < $totalPages,
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * @param list<int> $allowedPerPage
     */
    public static function normalizePerPage(int $perPage, array $allowedPerPage = self::DEFAULT_ALLOWED_PER_PAGE): int
    {
        $allowed = array_values(array_unique(array_filter(
            array_map('intval', $allowedPerPage),
            static fn(int $value): bool => $value > 0 && $value <= 200
        )));
        if ($allowed === []) {
            $allowed = self::DEFAULT_ALLOWED_PER_PAGE;
        }

        return in_array($perPage, $allowed, true) ? $perPage : $allowed[0];
    }

    /**
     * @return list<int|null> null は省略記号。
     */
    public static function window(int $page, int $totalPages, int $radius = 2): array
    {
        $totalPages = max(1, $totalPages);
        $page = max(1, min($page, $totalPages));
        $radius = max(0, min($radius, 5));

        if ($totalPages <= 7) {
            return range(1, $totalPages);
        }

        $pages = [1];
        $start = max(2, $page - $radius);
        $end = min($totalPages - 1, $page + $radius);

        if ($start > 2) {
            $pages[] = null;
        }
        for ($number = $start; $number <= $end; $number++) {
            $pages[] = $number;
        }
        if ($end < $totalPages - 1) {
            $pages[] = null;
        }
        $pages[] = $totalPages;

        return $pages;
    }
}
