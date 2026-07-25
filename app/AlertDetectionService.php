<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use RuntimeException;

final class AlertDetectionService
{
    public function __construct(
        private readonly AlertRepository $alerts,
        private readonly AlertRuleEvaluator $evaluator,
        private readonly AlertLockService $locks
    ) {
    }

    public function detect(
        int $propertyId,
        string $trigger = 'cli',
        ?int $actorId = null,
        ?int $window = null,
        ?string $asOf = null,
        bool $dryRun = false
    ): array {
        if ($propertyId < 1 || !in_array($trigger, ['web', 'cli', 'cron'], true)
            || ($window !== null && !in_array($window, [7, 28], true))
            || ($asOf !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf))) {
            throw new RuntimeException('検知条件が不正です。');
        }
        if ($asOf !== null) {
            $this->alerts->ranges($asOf, $window ?? 7);
        }
        if ($this->alerts->importRunning($propertyId)) {
            return $this->skipped($propertyId, $trigger, $actorId, null, '同期中のためスキップ', $dryRun);
        }
        $asOf ??= $this->alerts->latestDate($propertyId);
        if ($asOf === null) {
            return $this->skipped($propertyId, $trigger, $actorId, null, 'データ不足', $dryRun);
        }
        $latest = $this->alerts->latestDate($propertyId);
        if ($latest === null || $asOf > $latest) {
            return $this->skipped($propertyId, $trigger, $actorId, $asOf, '取得待ち', $dryRun);
        }
        $owner = $this->locks->acquire($propertyId, $trigger);
        $runId = $dryRun ? 0 : $this->alerts->startRun($propertyId, $trigger, $actorId, $asOf);
        $counts = [
            'rules_evaluated' => 0, 'subjects_evaluated' => 0, 'alerts_created' => 0,
            'alerts_updated' => 0, 'occurrences_created' => 0, 'suppressed_by_cooldown' => 0,
            'skipped_insufficient_data' => 0, 'errors_count' => 0,
        ];
        try {
            $cache = [];
            foreach ($this->alerts->enabledRules($window) as $rule) {
                $days = (int)$rule['comparison_days'];
                $range = $this->alerts->ranges($asOf, $days);
                $complete = $this->alerts->completeness($propertyId, $range, $days);
                if (!$complete['complete']) {
                    $counts['skipped_insufficient_data']++;
                    continue;
                }
                $cacheKey = $days . ':' . $rule['subject_type'];
                $cache[$cacheKey] ??= $this->alerts->aggregate($propertyId, (string)$rule['subject_type'], $range);
                $counts['rules_evaluated']++;
                foreach ($cache[$cacheKey] as $subject) {
                    $counts['subjects_evaluated']++;
                    try {
                        $match = $this->evaluator->evaluate($rule, $subject);
                        if ($match === null || $dryRun) {
                            continue;
                        }
                        $result = $this->alerts->record($propertyId, $rule, $subject, $match, $range, $asOf, $runId);
                        if ($result['occurrence_created']) {
                            $counts['occurrences_created']++;
                            $counts[$result['created'] ? 'alerts_created' : 'alerts_updated']++;
                        }
                        if ($result['cooldown_suppressed']) {
                            $counts['suppressed_by_cooldown']++;
                        }
                    } catch (\Throwable) {
                        $counts['errors_count']++;
                    }
                }
            }
            $status = $counts['errors_count'] > 0 ? 'partial_success'
                : ($counts['rules_evaluated'] === 0 ? 'skipped' : 'success');
            if (!$dryRun) {
                $reason = $status === 'skipped' ? '比較期間のデータが不足しています。' : null;
                $this->alerts->finishRun($runId, $status, $counts, $reason);
            }
            return ['status' => $status, 'as_of' => $asOf, 'dry_run' => $dryRun, 'run_id' => $runId] + $counts;
        } catch (\Throwable $e) {
            if (!$dryRun) {
                $counts['errors_count']++;
                $this->alerts->finishRun($runId, 'failed', $counts, '変動検知に失敗しました。');
            }
            throw $e;
        } finally {
            $this->locks->release($propertyId, $owner);
        }
    }

    private function skipped(
        int $propertyId,
        string $trigger,
        ?int $actorId,
        ?string $asOf,
        string $reason,
        bool $dryRun
    ): array {
        $runId = 0;
        if (!$dryRun) {
            $runId = $this->alerts->startRun($propertyId, $trigger, $actorId, $asOf);
            $this->alerts->finishRun($runId, 'skipped', ['skipped_insufficient_data' => 1], $reason);
        }
        return [
            'status' => 'skipped', 'reason' => $reason, 'as_of' => $asOf,
            'dry_run' => $dryRun, 'run_id' => $runId,
        ];
    }
}
