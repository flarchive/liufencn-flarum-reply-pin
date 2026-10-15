<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * 给 posts 表加「楼层置顶」两列：
 *   is_pinned  是否被置顶（0/1）
 *   pinned_at   置顶时间（NULL = 未置顶）
 *
 * 只给 type='comment' 的普通回复用；首帖/事件帖不参与。
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasColumn('posts', 'is_pinned')) {
            $schema->table('posts', function (Blueprint $table) {
                $table->boolean('is_pinned')->default(false)->after('type');
            });
        }

        if (! $schema->hasColumn('posts', 'pinned_at')) {
            $schema->table('posts', function (Blueprint $table) {
                $table->timestamp('pinned_at')->nullable()->after('is_pinned');
            });
        }

        // 置顶楼层按讨论 + 置顶时间倒序取，索引加速
        if (! $schema->hasIndex('posts', ['discussion_id', 'is_pinned'])) {
            $schema->table('posts', function (Blueprint $table) {
                $table->index(['discussion_id', 'is_pinned'], 'posts_disc_pinned_idx');
            });
        }
    },

    'down' => function (Builder $schema) {
        $schema->table('posts', function (Blueprint $table) {
            if ($schema->hasIndex('posts', ['discussion_id', 'is_pinned'])) {
                $table->dropIndex('posts_disc_pinned_idx');
            }
            $table->dropColumn(['is_pinned', 'pinned_at']);
        });
    },
];