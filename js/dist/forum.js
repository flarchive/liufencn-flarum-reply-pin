/**
 * 楼层置顶（liufencn-flarum-reply-pin）—— 手写 dist，不依赖 webpack 构建。
 *
 * 作用：把讨论内任意一条回复楼层钉到讨论页顶部，并加图钉徽标。
 *
 * ★ Flarum 2.0 前端扩展 API 要点（本文件据此实现，勿想当然）：
 *   1) extend(obj, method, cb)：cb 第一个参数是**原方法的返回值**，
 *      而且**不能替换返回值**（返回值固定是原方法的 value）。
 *      → 想改结构必须用 override(obj, method, newMethod)，
 *        其 newMethod 第一个参数是 original（类似 super）。
 *   2) extend/override 的第一个参数可以直接传**字符串路径**，
 *      核心会用 flarum.reg.onLoad() 在懒加载 chunk 就绪后自动打补丁 ——
 *      所以不需要自己写 import()/重试轮询。
 *   3) `common/extend` 模块导出 { extend, override }，不是默认导出。
 *   4) PostStream / Post / DiscussionPage 都在懒加载 chunk 里，
 *      脚本执行瞬间 reg.get() 拿不到，必须走上面的字符串路径。
 *
 * 功能：
 *   - PostControls.moderationControls → 每条楼层的「⋯」菜单加「置顶/取消置顶」
 *   - PostStream.view                → 顶部插入「置顶回复」区
 *   - Post.classes                   → 被置顶楼层加图钉边框类
 *   - admin: app.registry.registerPermission 注册 pinReply 权限
 */
(() => {
  const reg = window.flarum && window.flarum.reg;
  if (!reg) return;

  const unwrap = (mod) => (mod && mod.__esModule ? mod.default : mod);
  const m = window.m;

  const app = unwrap(reg.get('core', 'forum/app'));

  // common/extend 导出 { extend, override }，不是默认导出
  const extendMod = reg.get('core', 'common/extend');
  const extend = typeof extendMod === 'function' ? extendMod : (unwrap(extendMod) || extendMod).extend;
  const override = typeof extendMod === 'function' ? null : (unwrap(extendMod) || extendMod).override;

  const Button = unwrap(reg.get('core', 'common/components/Button'));
  const Component = unwrap(reg.get('core', 'common/Component'));

  const debug = { hasReg: !!reg, hasApp: !!app, hasM: !!m, hasExtend: typeof extend === 'function', hasOverride: typeof override === 'function', mounted: 0, pinned: 0 };
  try {
    window.__REPLY_PIN_DEBUG__ = debug;
  } catch (e) {
    /* ignore */
  }

  if (!app || !m || typeof extend !== 'function') {
    debug.aborted = 'missing app/m/extend';
    return;
  }

  const API_BASE = '/api/reply-pin';

  const t = (key, fallback) => {
    try {
      const v = app.translator.trans(key);
      if (typeof v === 'string') return v || fallback;
      // ★ translator.trans 可能返回对象（如带 {username} 占位的富文本）。
      //   这里只接受纯字符串；非字符串一律回落 fallback，
      //   否则 mithril 会把对象塞进 children，触发 (t||'').split is not a function。
      return fallback;
    } catch (e) {
      return fallback;
    }
  };

  /* ── 1. 后台注册权限（Flarum 2.0 走 app.registry） ── */
  try {
    if (app.registry && app.registry.registerPermission) {
      app.registry.registerPermission(
        {
          icon: 'fas fa-thumbtack',
          label: t('cathi-reply-pin.admin.pin_reply_label', '置顶回复楼层'),
          permission: 'pinReply',
        },
        'moderate',
        60
      );
    }
  } catch (e) {
    /* admin 侧没有 app.registry，忽略 */
  }

  /* ── 2. 置顶 / 取消置顶 ── */
  const setPinned = async (post, pin) => {
    const id = post && post.id ? post.id() : null;
    if (!id) return;

    try {
      await app.request({
        url: API_BASE + (pin ? '/pin' : '/unpin'),
        method: 'POST',
        body: { data: { postId: id } },
      });

      // 只更新前端 store，不回写服务器（已由 API 落库）
      post.pushData({ attributes: { isPinned: !!pin, pinnedAt: pin ? new Date().toISOString() : null } });

      m.redraw();

      // 通知置顶区重载
      window.dispatchEvent(new CustomEvent('cathi-reply-pin:changed', { detail: { postId: id, pinned: !!pin } }));
      debug.pinned++;
    } catch (err) {
      console.error('[cathi-reply-pin] 置顶操作失败', err);
      // 把服务端的中文错误提示直接抛给用户
      const detail = err && err.response && err.response.status === 403
        ? t('cathi-reply-pin.forum.no_permission', '你没有楼层置顶权限')
        : t('cathi-reply-pin.forum.operate_failed', '置顶操作失败');
      alert(detail);
    }
  };

  /* ── 3. 每条楼层的「置顶 / 取消置顶」菜单项 ── */
  // ★ PostControls 是**普通对象**（不是 class），没有 .prototype。
  //   核心 extend()/override() 传字符串路径时会无条件走 `extend(module.prototype, …)`，
  //   对它会抛 "Cannot read properties of undefined (reading 'moderationControls')"。
  //   所以这里用已加载的实例对象直接打补丁（reg.get 对它一直可用，见下方 debug）。
  const PostControls = unwrap(reg.get('core', 'forum/utils/PostControls'));

  if (PostControls && Button) {
    extend(PostControls, 'moderationControls', function (items, post) {
      try {
        if (post && post.attribute && post.attribute('canPinReply')) {
          const pinned = !!post.attribute('isPinned');

          items.add(
            'replyPin',
            Button.component(
              {
                icon: pinned ? 'far fa-thumbtack' : 'fas fa-thumbtack',
                onclick: () => setPinned(post, !pinned),
              },
              pinned ? t('cathi-reply-pin.forum.unpin_button', '取消置顶') : t('cathi-reply-pin.forum.pin_button', '置顶回复')
            )
          );
        }
      } catch (e) {
        /* ignore */
      }
      // ★ 必须原样返回 items：extend 的返回值固定用原方法的 value，
      //   这里返回仅作语义清晰，核心不会采纳。
      return items;
    });
  }

  /* ── 4. 帖子流顶部的「置顶回复」区 ── */
  class PinnedReplies extends Component {
    oninit() {
      super.oninit(...arguments);
      this.state = 'loading'; // loading | ready | empty | error
      this.items = [];
      this.load();
      this._onChanged = () => this.load();
      window.addEventListener('cathi-reply-pin:changed', this._onChanged);
    }

    onremove() {
      super.onremove(...arguments);
      window.removeEventListener('cathi-reply-pin:changed', this._onChanged);
    }

    async load() {
      const discussionId = this.attrs.discussion && this.attrs.discussion.id ? this.attrs.discussion.id() : null;
      if (!discussionId) {
        this.state = 'empty';
        m.redraw();
        return;
      }

      this.state = 'loading';
      m.redraw();

      try {
        const res = await app.request({ url: API_BASE + '/pinned?discussionId=' + discussionId, method: 'GET' });
        this.items = (res && res.data) || [];
        this.state = this.items.length ? 'ready' : 'empty';
      } catch (e) {
        this.items = [];
        this.state = 'error';
      }

      // ★ 关键：view() 在 loading 时返回 null，mithril 会把该子树**移除**。
      //   异步回来后必须 redraw 一次，否则组件已被卸载、不会重新渲染，
      //   表现就是「接口有数据但页面永远不显示置顶区」。
      m.redraw();
    }

    jumpTo(item, vnode) {
      const target = document.getElementById('post' + item.id);
      if (!target) return;
      target.scrollIntoView({ behavior: 'smooth', block: 'center' });
      target.classList.add('Post--replyPinTarget');
      setTimeout(() => target.classList.remove('Post--replyPinTarget'), 1600);
    }

    view() {
      // ★ 不要在 loading/empty 时 return null —— mithril 会把该子树从 DOM 移除，
      //   组件随之失联；异步数据回来后再 m.redraw() 也不会重新挂载（表现为接口有数据、页面永远空白）。
      //   改为返回一个隐藏的占位 vnode，组件全程保持挂载。
      if (this.state === 'loading' || this.state === 'empty' || !this.items.length) {
        return m('div', { className: 'ReplyPin-PinnedPlaceholder', style: 'display:none' });
      }

      return m(
        'div',
        { className: 'ReplyPin-Pinned' + (this.state === 'error' ? ' ReplyPin-Pinned--error' : '') },
        m(
          'div',
          { className: 'ReplyPin-PinnedHeader' },
          m('i', { className: 'fas fa-thumbtack ReplyPin-PinnedHeaderIcon' }),
          m('span', {}, t('cathi-reply-pin.forum.pinned_title', '置顶回复')),
          m('span', { className: 'ReplyPin-PinnedCount' }, String(this.items.length))
        ),
        m(
          'ul',
          { className: 'ReplyPin-PinnedList' },
          this.items.map((item) =>
            m(
              'li',
              { className: 'ReplyPin-PinnedItem', key: 'replypin-' + item.id },
              m('i', { className: 'fas fa-thumbtack ReplyPin-PinnedItemIcon' }),
              m('a', { className: 'ReplyPin-PinnedLink', href: item.url, oncreate: (v) => this.jumpTo(item, v) }, item.excerpt),
              m(
                'a',
                { className: 'ReplyPin-PinnedJump', href: item.url, oncreate: (v) => this.jumpTo(item, v) },
                m('i', { className: 'fas fa-location-arrow' }),
                ' ' + t('cathi-reply-pin.forum.goto_floor', '跳到该楼层')
              )
            )
          )
        )
      );
    }
  }

  /* ── 5. 把「置顶回复」区挂到 PostStream 顶部 ── */
  // ★ 必须用 override：extend 不能替换返回值，而这里要往 children 里 unshift。
  if (typeof override === 'function') {
    override('flarum/forum/components/PostStream', 'view', function (original) {
      // ★ arguments[0] 就是 original 本身（核心用 apply 传入），真正的调用参数从 arguments[1] 起。
      //   写成 original.apply(this, arguments) 会把 original 当成第一个实参传进去 —— 对 view()
      //   影响不大（无参），但对 classes(existing) 这类有参方法是致命的：
      //   existing 变成一个函数，核心内部 (existing || '').split(' ') 直接抛
      //   "(t || "").split is not a function"。
      const vnode = original.apply(this, Array.prototype.slice.call(arguments, 1));

      try {
        if (vnode && Array.isArray(vnode.children) && this.attrs && this.attrs.discussion) {
          // ★ 去重判断必须用组件 vnode 上真实存在的属性（attrs）。
          //   组件 vnode 没有 className，用 className 判断永远为 false →
          //   每次 render 都 unshift 一次 → mithril keyed diff 错位 → removeChild 报错。
          const already = vnode.children.some((c) => c && c.attrs && c.attrs.__replyPin);

          if (!already) {
            vnode.children.unshift(
              m(PinnedReplies, { discussion: this.attrs.discussion, key: 'replyPinPinned', __replyPin: true })
            );
            debug.mounted++;
          }
        }
      } catch (e) {
        /* ignore */
      }

      return vnode;
    });
  }

  /* ── 6. 被置顶楼层加图钉左边框 ── */
  if (typeof override === 'function') {
    override('flarum/forum/components/Post', 'classes', function (original) {
      // arguments[0] 是 original，真正的实参（existing className 字符串）从 arguments[1] 起
      const existing = Array.prototype.slice.call(arguments, 1)[0];
      const classes = original.call(this, existing) || [];
      try {
        if (this.attrs && this.attrs.post && this.attrs.post.attribute && this.attrs.post.attribute('isPinned')) {
          classes.push('Post--replyPinned');
        }
      } catch (e) {
        /* ignore */
      }
      return classes;
    });
  }

  /* ── 7. 供其它扩展/调试读取 ── */
  window.__REPLY_PIN__ = { setPinned, PinnedReplies, debug };

  // ★★★ 关键：必须导出 module.exports = { extend: [] }。
  //   Flarum 2.0 的 JsCompiler 把本 dist 直接拼进 forum.js，并在每个扩展源码后追加
  //       flarum.extensions['liufencn-flarum-reply-pin'] = module.exports;
  //   核心 bootExtensions 随后读该值的 .extend。若不定义 module.exports，
  //   module 会解析到核心自己的 module 对象，这行赋值污染核心模块注册表，
  //   导致后续扩展 boot 抛 "Cannot read properties of undefined (reading 'extend')"，
  //   整站 JS 启动中断。既有扩展 cathi-tagcreate 末尾有同样守卫，务必保留。
  if (typeof module !== 'undefined' && module) {
    module.exports = { extend: [] };
  }
})();
