<?php

declare(strict_types=1);

namespace Mitalteli\Webtrees\Util\Reminders;

use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\ModuleService;
use Fisharebest\Webtrees\Services\RelationshipService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Module\RelationshipsChartModule;
use Fisharebest\Webtrees\Tree;
use ReflectionMethod;
use SplDoublyLinkedList;

/**
 * Relationship distance + label between two individuals.
 *
 * Distance model (kept stable so existing subscriber max_distance settings
 * keep meaning the same thing):
 *   - spouse edge  = 0 (spouse of a relative shares that relative's distance)
 *   - parent edge  = 1
 *   - child edge   = 1
 *   - sibling edge = 1   (so: grandparent/aunt/uncle = 2, first cousin = 3)
 *
 * Implemented as a single bounded 0-1 BFS from the subscriber, yielding an
 * xref => distance map (computed once per subscriber, then looked up per event).
 */
class ReminderRelationships
{
    private RelationshipService $relationship_service;

    public function __construct(RelationshipService $relationship_service)
    {
        $this->relationship_service = $relationship_service;
    }

    /**
     * Shortest distance from $from to every individual within $max_distance.
     *
     * @return array<string,int> xref => distance (includes $from at 0)
     */
    public function distancesFrom(Individual $from, int $max_distance): array
    {
        $tree = $from->tree();
        $dist = [$from->xref() => 0];

        // 0-1 BFS: 0-cost edges to the front, 1-cost edges to the back.
        $deque = new SplDoublyLinkedList();
        $deque->push([$from->xref(), 0]);

        while (!$deque->isEmpty()) {
            [$xref, $d] = $deque->shift();

            // Skip stale queue entries (a better distance was found since).
            if ($d > ($dist[$xref] ?? PHP_INT_MAX)) {
                continue;
            }

            $individual = Registry::individualFactory()->make($xref, $tree);

            if ($individual === null) {
                continue;
            }

            // Spouses: cost 0.
            foreach ($this->spouseXrefs($individual) as $nx) {
                if ($d < ($dist[$nx] ?? PHP_INT_MAX) && $d <= $max_distance) {
                    $dist[$nx] = $d;
                    $deque->unshift([$nx, $d]);
                }
            }

            // Parents, children, siblings: cost 1.
            if ($d + 1 <= $max_distance) {
                foreach ($this->bloodXrefs($individual) as $nx) {
                    if ($d + 1 < ($dist[$nx] ?? PHP_INT_MAX)) {
                        $dist[$nx] = $d + 1;
                        $deque->push([$nx, $d + 1]);
                    }
                }
            }
        }

        return $dist;
    }

    /** BFS depth used when looking for a relationship path. webtrees' built-in
     *  `getCloseRelationshipName` caps at 4 — too short for the family tree's
     *  more distant cousins. 12 covers up to "5th cousin twice removed" territory. */
    private const PATH_MAX_LENGTH = 12;

    /**
     * Human-readable relationship label between two individuals, of any
     * distance up to PATH_MAX_LENGTH. Falls back to '' only if the BFS truly
     * fails to find a path within that budget.
     */
    public function label(Individual $from, Individual $to): string
    {
        if ($from->xref() === $to->xref()) {
            return 'You';
        }

        $path = $this->findPath($from, $to);
        if ($path === []) {
            return '';
        }

        $language = Registry::container()
            ->get(ModuleService::class)
            ->findByInterface(\Fisharebest\Webtrees\Module\ModuleLanguageInterface::class, true)
            ->first(static fn (\Fisharebest\Webtrees\Module\ModuleLanguageInterface $l): bool => $l->locale()->languageTag() === I18N::languageTag());

        if ($language === null) {
            return '';
        }

        return $this->relationship_service->nameFromPath($path, $language);
    }

    /**
     * Calls the (private) `RelationshipService::getCloseRelationship` via
     * reflection with our larger PATH_MAX_LENGTH budget — webtrees has no
     * public equivalent. Returns the path array nameFromPath() expects.
     *
     * @return array<int,object>
     */
    private function findPath(Individual $from, Individual $to): array
    {
        static $reflection = null;
        if ($reflection === null) {
            $reflection = new ReflectionMethod(RelationshipService::class, 'getCloseRelationship');
            $reflection->setAccessible(true);
        }
        $path = $reflection->invoke($this->relationship_service, $from, $to, self::PATH_MAX_LENGTH);
        return is_array($path) ? $path : [];
    }

    /**
     * Resolve the relationship for the individual-page card.
     *
     * Strategy (per JB): try to find the relationship VIA COMMON ANCESTORS first
     * — the genealogically meaningful lineage (e.g. "maternal great ×24
     * grandfather") — and only if that finds nothing fall back to the closest
     * relationship of ANY kind (marriage / step links included). Both passes run
     * at recursion = 0 (the single closest path): higher recursion on this tree
     * is pathologically slow (measured 6 s at rec=3 via-ancestors, 47 s at rec=3
     * any-relationship) and is never wanted for a label.
     *
     * Uses the same engine as the web "Relationships" chart
     * (RelationshipsChartModule::calculateRelationships) so the card label always
     * matches the chart, and it reaches arbitrarily distant ancestors (the legacy
     * getCloseRelationship path used by the digest caps at PATH_MAX_LENGTH hops).
     *
     * @return array{label:string,ancestors:?bool} ancestors: true = found via
     *         ancestors, false = via any-relationship, null = self / not found.
     */
    public function resolveForCard(Individual $from, Individual $to): array
    {
        if ($from->xref() === $to->xref()) {
            return ['label' => 'You', 'ancestors' => null];
        }

        foreach ([true, false] as $ancestors) {
            $nodes = $this->closestPath($from, $to, $ancestors);
            if ($nodes !== []) {
                $label = $this->labelFromPath($nodes);
                if ($label !== '') {
                    return ['label' => $label, 'ancestors' => $ancestors];
                }
            }
        }

        return ['label' => '', 'ancestors' => null];
    }

    /**
     * Best relationship label, used by the upcoming-birthdays list and the email
     * digest. Tries the fast depth-PATH_MAX_LENGTH finder first (instant for the
     * close kin that fill most of a digest); if that finds nothing — distant
     * ancestors / far cousins, e.g. a FOLLOWED person well outside max_distance
     * like a great-x24 grandfather or a 4th cousin — it falls back to the smart
     * ancestors-first chart-engine finder so EVERY relationship resolves.
     * Returns '' only when there is genuinely no path (or self).
     */
    public function smartLabel(Individual $from, Individual $to): string
    {
        if ($from->xref() === $to->xref()) {
            return '';
        }

        $fast = $this->label($from, $to); // depth-12 getCloseRelationship
        if ($fast !== '') {
            return $fast;
        }

        $smart = $this->resolveForCard($from, $to); // chart engine, any distance
        return $smart['label'] === 'You' ? '' : $smart['label'];
    }

    /**
     * The single closest relationship path (as record objects) from $from to $to,
     * via the chart module's Dijkstra finder at recursion 0. $ancestors restricts
     * the graph to common-ancestor paths. Returns [] if no path.
     *
     * @return array<int,object>
     */
    private function closestPath(Individual $from, Individual $to, bool $ancestors): array
    {
        static $chart = null;
        static $ref   = null;
        if ($ref === null) {
            $chart = new RelationshipsChartModule(
                $this->relationship_service,
                Registry::container()->get(TreeService::class)
            );
            $ref = new ReflectionMethod(RelationshipsChartModule::class, 'calculateRelationships');
            $ref->setAccessible(true);
        }

        // Returns an ASSOCIATIVE array (keyed by path-string) of paths; each path
        // is an array of alternating INDI/FAM xref strings.
        $paths = $ref->invoke($chart, $from, $to, 0, $ancestors);
        if (!is_array($paths) || $paths === []) {
            return [];
        }

        // Closest = fewest nodes.
        $best = null;
        foreach ($paths as $candidate) {
            if ($best === null || count($candidate) < count($best)) {
                $best = $candidate;
            }
        }

        // Convert xref strings to records (even index = INDI, odd = FAM).
        $tree  = $from->tree();
        $nodes = [];
        foreach (array_values($best) as $i => $xref) {
            $record = ($i % 2 === 0)
                ? Registry::individualFactory()->make((string) $xref, $tree)
                : Registry::familyFactory()->make((string) $xref, $tree);
            if ($record === null) {
                return [];
            }
            $nodes[] = $record;
        }

        return $nodes;
    }

    /** Localised label for a resolved path, or '' if the language is unavailable. */
    private function labelFromPath(array $path): string
    {
        $language = Registry::container()
            ->get(ModuleService::class)
            ->findByInterface(\Fisharebest\Webtrees\Module\ModuleLanguageInterface::class, true)
            ->first(static fn (\Fisharebest\Webtrees\Module\ModuleLanguageInterface $l): bool => $l->locale()->languageTag() === I18N::languageTag());

        if ($language === null) {
            return '';
        }

        return $this->relationship_service->nameFromPath($path, $language);
    }

    /**
     * @return array<int,string>
     */
    private function spouseXrefs(Individual $individual): array
    {
        $out = [];

        foreach ($individual->spouseFamilies() as $family) {
            foreach ($family->spouses() as $spouse) {
                if ($spouse->xref() !== $individual->xref()) {
                    $out[] = $spouse->xref();
                }
            }
        }

        return $out;
    }

    /**
     * Parents + siblings (via child families) and children (via spouse families).
     *
     * @return array<int,string>
     */
    private function bloodXrefs(Individual $individual): array
    {
        $out = [];

        foreach ($individual->childFamilies() as $family) {
            foreach ($family->spouses() as $parent) {
                $out[] = $parent->xref();
            }
            foreach ($family->children() as $child) {
                if ($child->xref() !== $individual->xref()) {
                    $out[] = $child->xref();
                }
            }
        }

        foreach ($individual->spouseFamilies() as $family) {
            foreach ($family->children() as $child) {
                $out[] = $child->xref();
            }
        }

        return $out;
    }
}
