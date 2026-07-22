<?php
use Tenyendama\SeoWatch\Csrf;
use Tenyendama\SeoWatch\UserAccountPolicy;
use Tenyendama\SeoWatch\View;
?>
<div class="user-management-grid">
<section class="card">
    <div class="card-head">
        <div>
            <h2>閲覧ユーザーを追加</h2>
            <p>分析画面だけを見られるアカウントを発行します。</p>
        </div>
    </div>
    <form method="post" action="index.php?r=users/create" class="stack-form" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>">
        <label>ユーザー名
            <input name="username" minlength="3" maxlength="64" autocomplete="off" required>
        </label>
        <label>初期パスワード
            <input type="password" name="password" minlength="10" autocomplete="new-password" required>
        </label>
        <label>初期パスワード（確認）
            <input type="password" name="password_confirmation" minlength="10" autocomplete="new-password" required>
        </label>
        <p class="hint">閲覧ユーザーはダッシュボード・伸びしろ・検索語・ページ・記事詳細だけを利用できます。設定やデータ更新は実行できません。</p>
        <button class="button primary" type="submit">閲覧ユーザーを作成</button>
    </form>
</section>

<section class="card user-policy-card">
    <h2>権限の境界</h2>
    <dl class="permission-list">
        <div><dt>分析結果の閲覧</dt><dd>全ユーザー</dd></div>
        <div><dt>Google OAuth・プロパティ設定</dt><dd>スーパーユーザーのみ</dd></div>
        <div><dt>データ取り込み・URL正規化</dt><dd>スーパーユーザーのみ</dd></div>
        <div><dt>ユーザー作成・削除</dt><dd>スーパーユーザーのみ</dd></div>
    </dl>
    <div class="notice compact-notice">メニューを非表示にするだけでなく、制限対象URLへ直接アクセスした場合もサーバー側で403を返します。</div>
</section>

<section class="card full-span">
    <div class="card-head">
        <div>
            <h2>登録ユーザー</h2>
            <p><?=number_format(count($users))?>アカウント</p>
        </div>
    </div>
    <div class="table-card flat">
        <table class="users-table">
            <thead><tr><th>ユーザー名</th><th>権限</th><th>最終ログイン</th><th>作成日時</th><th class="num">操作</th></tr></thead>
            <tbody>
            <?php foreach ($users as $user): ?>
                <?php $isRoot = UserAccountPolicy::isSuperuser((string)$user['role']); ?>
                <tr>
                    <td><strong><?=View::e($user['username'])?></strong><?php if ((int)$user['id'] === (int)($currentUser['id'] ?? 0)): ?><span class="current-user-mark">現在のユーザー</span><?php endif; ?></td>
                    <td><span class="role-badge <?=$isRoot ? 'superuser' : 'viewer'?>"><?=View::e(UserAccountPolicy::roleLabel((string)$user['role']))?></span></td>
                    <td><?=View::e($user['last_login_at'] ?: '未ログイン')?></td>
                    <td><?=View::e($user['created_at'])?></td>
                    <td class="num">
                        <?php if ($isRoot): ?>
                            <span class="muted small-text">削除不可</span>
                        <?php else: ?>
                            <form method="post" action="index.php?r=users/delete" class="inline-form" data-confirm="この閲覧ユーザーを削除しますか？">
                                <input type="hidden" name="_csrf" value="<?=View::e(Csrf::token())?>">
                                <input type="hidden" name="user_id" value="<?=(int)$user['id']?>">
                                <button class="button danger-button small-button" type="submit">削除</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
</div>
