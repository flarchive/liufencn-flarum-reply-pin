<?php

/**
 * 楼层置顶（liufencn/flarum-reply-pin）
 *
 * 把讨论内任意一条「回复楼层」钉到讨论页面顶部，并加图钉徽标。
 * 与官方 flarum-sticky（置顶整个讨论）不同，这里置顶的是讨论内的单条回复。
 */

use Cathi\ReplyPin\Api\Resource\PostResourceFields;
use Flarum\Api\Resource\PostResource;
use Flarum\Extend;
use Flarum\Post\Post;

// 手动部署的扩展没有 composer PSR-4 autoload，这里显式引入类文件，
// 使 Flarum 路由分发器（容器 make）能实例化这些控制器 / 字段类。
require_once __DIR__ . '/src/Api/Controller/PinController.php';
require_once __DIR__ . '/src/Api/Controller/UnpinController.php';
require_once __DIR__ . '/src/Api/Controller/ListPinnedController.php';
require_once __DIR__ . '/src/Api/Resource/PostResourceFields.php';
require_once __DIR__ . '/src/Api/Resource/DiscussionPinnedFields.php';

return [
    // ─── 语言包 ───
    new Extend\Locales(__DIR__ . '/resources/locale'),

    // ─── 前端资源 ───
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/less/forum.less'),

    // ─── 后端 API ───
    //   POST /api/reply-pin/pin    { postId }
    //   POST /api/reply-pin/unpin  { postId }
    //   GET  /api/reply-pin/pinned?discussionId=<id>
    // ★ 控制器必须写全限定名：本文件无 namespace，`Api\Controller\X` 这种相对名
    //   经 ::class 解析出来就是字面量 "Api\Controller\X"（use 别名对 ::class 不生效），
    //   容器 make() 会抛「Class Api\Controller\X does not exist」。
    (new Extend\Routes('api'))
        ->post('/reply-pin/pin', 'reply-pin.pin', \Cathi\ReplyPin\Api\Controller\PinController::class)
        ->post('/reply-pin/unpin', 'reply-pin.unpin', \Cathi\ReplyPin\Api\Controller\UnpinController::class)
        ->get('/reply-pin/pinned', 'reply-pin.pinned', \Cathi\ReplyPin\Api\Controller\ListPinnedController::class),

    // ─── 模型：is_pinned 转 bool、pinned_at 转 datetime ───
    (new Extend\Model(Post::class))
        ->cast('is_pinned', 'bool')
        ->cast('pinned_at', 'datetime'),

    // ─── Post 资源注入字段：isPinned / pinnedAt / canPinReply ───
    (new Extend\ApiResource(PostResource::class))
        ->fields(PostResourceFields::class),

    // ─── Discussion 资源注入：pinnedPostIds（本次返回的楼层里哪些被置顶）───
    (new Extend\ApiResource(\Flarum\Api\Resource\DiscussionResource::class))
        ->fields(\Cathi\ReplyPin\Api\Resource\DiscussionPinnedFields::class),
];