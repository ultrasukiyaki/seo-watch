<?php
use Tenyendama\SeoWatch\Csrf;
use Tenyendama\SeoWatch\View;
?>
<section class="panel">
    <form method="get" class="filters">
        <input type="hidden" name="r" value="improvements">
        <label>状態
            <select name="status">
                <option value="">すべて</option>
                <?php foreach (['open'=>'未対応','in_progress'=>'対応中','completed'=>'完了','on_hold'=>'保留','ignored'=>'対象外'] as $value => $label): ?>
                <option value="<?=View::e($value)?>" <?=($filters['status'] ?? '') === $value ? 'selected' : ''?>><?=View::e($label)?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit">絞り込む</button>
    </form>
</section>

<?php if (!empty($isSuperuser) && !empty($activeProperty)): ?>
<details class="panel">
    <summary>手動でタスクを作成</summary>
    <form method="post" action="index.php?r=improvements/create">
        <input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>">
        <label>記事URL <input type="url" name="normalized_page_url" required></label>
        <label>種別 <select name="task_type"><?php foreach (['title','heading','ctr','ranking','content','internal_link','technical','other'] as $type): ?><option><?=View::e($type)?></option><?php endforeach; ?></select></label>
        <label>タイトル <input type="text" name="title" maxlength="255" required></label>
        <label>説明 <textarea name="description"></textarea></label>
        <label>元検索語 <input type="text" name="source_query"></label>
        <button type="submit">タスクを追加</button>
    </form>
</details>
<?php endif; ?>

<section class="panel">
<?php if (!$tasks): ?>
    <p>該当する改善タスクはありません。</p>
<?php else: ?>
    <div class="table-wrap"><table>
        <thead><tr><th>状態</th><th>記事・タスク</th><th>種別</th><th>担当</th><th>修正日</th><th>更新</th></tr></thead>
        <tbody>
        <?php foreach ($tasks as $task): ?>
        <tr>
            <td>
            <?php if (!empty($isSuperuser)): ?>
                <form method="post" action="index.php?r=improvements/update">
                    <input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>">
                    <input type="hidden" name="task_id" value="<?=(int)$task['id']?>">
                    <select name="status" aria-label="状態">
                    <?php foreach (['open'=>'未対応','in_progress'=>'対応中','completed'=>'完了','on_hold'=>'保留','ignored'=>'対象外'] as $value => $label): ?>
                        <option value="<?=View::e($value)?>" <?=$task['status'] === $value ? 'selected' : ''?>><?=View::e($label)?></option>
                    <?php endforeach; ?>
                    </select>
                    <input type="date" name="revision_date" value="<?=View::e((string)$task['revision_date'])?>" aria-label="記事修正日">
                    <select name="assigned_user_id" aria-label="担当者">
                        <option value="">未割当</option>
                        <?php foreach ($users as $user): ?>
                        <option value="<?=(int)$user['id']?>" <?=(int)($task['assigned_user_id'] ?? 0) === (int)$user['id'] ? 'selected' : ''?>><?=View::e($user['username'])?></option>
                        <?php endforeach; ?>
                    </select>
                    <textarea name="note" aria-label="メモ"><?=View::e((string)$task['note'])?></textarea>
                    <button type="submit">更新</button>
                </form>
            <?php else: ?>
                <?=View::e(['open'=>'未対応','in_progress'=>'対応中','completed'=>'完了','on_hold'=>'保留','ignored'=>'対象外'][$task['status']] ?? $task['status'])?>
            <?php endif; ?>
            </td>
            <td><strong><?=View::e($task['title'])?></strong><br><a href="<?=View::e($task['normalized_page_url'])?>" target="_blank" rel="noopener"><?=View::e($task['normalized_page_url'])?></a></td>
            <td><?=View::e($task['task_type'])?></td>
            <td><?=View::e((string)($task['assigned_username'] ?? '未割当'))?></td>
            <td><?=View::e((string)($task['revision_date'] ?? '—'))?></td>
            <td><?=View::e($dateTime->detail($task['updated_at']))?></td>
        </tr>
        <?php if (!empty($task['comparison']) || !empty($task['history'])): $comparison = $task['comparison']; ?>
        <tr><td colspan="6">
            <?php if ($comparison): ?>
            <details>
                <summary>修正前後の簡易比較（<?=$comparison['is_final'] ? '確定' : '暫定'?>）</summary>
                <p>
                    前 <?=View::e($comparison['before']['start'])?>〜<?=View::e($comparison['before']['end'])?>:
                    <?=number_format($comparison['before']['clicks'])?>クリック /
                    <?=number_format($comparison['before']['impressions'])?>表示 /
                    <?=number_format($comparison['before']['ctr'] * 100, 2)?>% /
                    順位 <?=number_format((float)$comparison['before']['position'], 1)?>
                </p>
                <p>
                    後 <?=View::e($comparison['after']['start'])?>〜<?=View::e($comparison['after']['end'])?>:
                    <?=number_format($comparison['after']['clicks'])?>クリック /
                    <?=number_format($comparison['after']['impressions'])?>表示 /
                    <?=number_format($comparison['after']['ctr'] * 100, 2)?>% /
                    順位 <?=number_format((float)$comparison['after']['position'], 1)?>
                </p>
                <?php if (!$comparison['is_final']): ?><p>効果測定まで残り<?=number_format($comparison['remaining_days'])?>日（現在取得済み: <?=number_format($comparison['after']['data_days'])?>日 / 28日）</p><?php endif; ?>
                <p class="hint"><?=View::e($comparison['notice'])?></p>
            </details>
            <?php endif; ?>
            <?php if (!empty($task['history'])): ?>
            <details><summary>最新履歴</summary><ul><?php foreach ($task['history'] as $history): ?><li><?=View::e($dateTime->detail($history['created_at']))?> — <?=View::e($history['event_type'])?> (<?=View::e((string)($history['username'] ?? 'system'))?>)</li><?php endforeach; ?></ul></details>
            <?php endif; ?>
        </td></tr>
        <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
</section>
