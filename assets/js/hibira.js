/**
 * Hibira Realtime for WordPress: the browser side.
 *
 * HibiraWP.pusher() is one pusher-js connection for the page, also for your
 * own code:
 *
 *   HibiraWP.pusher().subscribe('scores').bind('goal', (data) => ...);
 */
(function () {
  'use strict';

  var config = window.HibiraConfig || {};
  var t = config.i18n || {};
  var client = null;
  var visitorKey = 'hibira-visitor';

  function visitor() {
    try {
      var id = localStorage.getItem(visitorKey);
      if (!id) {
        id = (crypto.randomUUID && crypto.randomUUID()) || String(Math.random()).slice(2) + String(Date.now());
        localStorage.setItem(visitorKey, id);
      }
      return id;
    } catch (e) {
      return '';
    }
  }

  var extraParams = {};

  function pusher() {
    if (client) return client;
    var headers = {};
    if (config.nonce) headers['X-WP-Nonce'] = config.nonce;
    client = new window.Pusher(config.key, {
      cluster: 'hkg', // required by pusher-js, unused once wsHost is set
      wsHost: config.host,
      wsPort: config.port,
      wssPort: config.port,
      forceTLS: !!config.tls,
      enabledTransports: ['ws', 'wss'],
      channelAuthorization: {
        endpoint: config.authUrl,
        transport: 'ajax',
        headers: headers,
        paramsProvider: function () {
          var p = { visitor: visitor() };
          for (var k in extraParams) p[k] = extraParams[k];
          return p;
        },
      },
    });
    return client;
  }

  function fmt(s, v) {
    return String(s || '').replace('%s', v);
  }

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }

  /** A small notice at the bottom of the screen, with optional actions. */
  function toast(message, actions) {
    var box = document.querySelector('.hibira-toasts');
    if (!box) {
      box = el('div', 'hibira-toasts');
      box.setAttribute('role', 'status');
      box.setAttribute('aria-live', 'polite');
      document.body.appendChild(box);
    }
    var item = el('div', 'hibira-toast');
    item.appendChild(el('span', 'hibira-toast-text', message));
    (actions || []).forEach(function (a) {
      var b = el(a.href ? 'a' : 'button', 'hibira-toast-action', a.label);
      if (a.href) b.href = a.href;
      else {
        b.type = 'button';
        b.addEventListener('click', a.onClick);
      }
      item.appendChild(b);
    });
    var close = el('button', 'hibira-toast-close', '×');
    close.type = 'button';
    close.setAttribute('aria-label', t.dismiss || 'Dismiss');
    close.addEventListener('click', function () {
      item.remove();
    });
    item.appendChild(close);
    box.appendChild(item);
    return item;
  }

  // --- Posts ---------------------------------------------------------------

  function commentList() {
    return document.querySelector('ol.comment-list, ul.comment-list, .wp-block-comment-template');
  }

  /** Classic comment markup, which most themes style. */
  function commentNode(c) {
    var li = el('li', 'comment hibira-live-comment');
    li.id = 'comment-' + c.id;
    var article = el('article', 'comment-body');
    article.id = 'div-comment-' + c.id;
    var footer = el('footer', 'comment-meta');
    var author = el('div', 'comment-author vcard');
    if (c.avatar) {
      var img = el('img', 'avatar avatar-32 photo');
      img.src = c.avatar;
      img.alt = '';
      img.width = 32;
      img.height = 32;
      author.appendChild(img);
    }
    var name = el(c.url ? 'a' : 'b', 'fn', c.author);
    if (c.url) {
      name.href = c.url;
      name.rel = 'external nofollow ugc';
    }
    author.appendChild(name);
    footer.appendChild(author);
    var meta = el('div', 'comment-metadata');
    var link = el('a', null);
    link.href = c.link;
    var time = el('time', null, c.date);
    time.setAttribute('datetime', c.iso);
    link.appendChild(time);
    meta.appendChild(link);
    footer.appendChild(meta);
    article.appendChild(footer);
    var content = el('div', 'comment-content');
    // Comment HTML comes from WordPress, filtered by kses when it was saved.
    content.innerHTML = c.html;
    article.appendChild(content);
    li.appendChild(article);
    return li;
  }

  function addComment(c) {
    if (document.getElementById('comment-' + c.id)) return;
    var list = commentList();
    if (!list || !c.html) {
      toast(t.newComment + ': ' + c.author, [{ label: t.view, href: c.link }]);
      return;
    }
    var parent = c.parent && document.getElementById('comment-' + c.parent);
    var node = commentNode(c);
    if (parent) {
      var children = parent.querySelector(':scope > ol.children, :scope > ul.children');
      if (!children) {
        children = el('ol', 'children');
        parent.appendChild(children);
      }
      children.appendChild(node);
    } else {
      list.appendChild(node);
    }
  }

  function showReaders(count) {
    var spots = document.querySelectorAll('[data-hibira-readers]');
    if (!spots.length) {
      var pill = el('span', 'hibira-readers hibira-readers-float');
      pill.setAttribute('data-hibira-readers', '');
      document.body.appendChild(pill);
      spots = [pill];
    }
    var text = count >= 100 ? t.readersMany : fmt(t.readers, count);
    Array.prototype.forEach.call(spots, function (s) {
      s.textContent = text;
      s.hidden = false;
    });
  }

  function watchPost(opts) {
    var channel = pusher().subscribe('hibira-post-' + opts.id);
    if (opts.postUpdates) {
      var shown = false;
      channel.bind('post-updated', function (d) {
        if (shown || (d && d.modified && d.modified <= opts.modified)) return;
        shown = true;
        toast(t.updated, [{ label: t.reload, onClick: function () { location.reload(); } }]);
      });
    }
    if (opts.liveComments) channel.bind('comment-approved', addComment);
    if (opts.readers) {
      var presence = pusher().subscribe('presence-hibira-post-' + opts.id);
      var update = function () {
        showReaders(presence.members.count);
      };
      presence.bind('pusher:subscription_succeeded', update);
      presence.bind('pusher:member_added', update);
      presence.bind('pusher:member_removed', update);
    }
  }

  // --- WooCommerce ---------------------------------------------------------

  function watchOrder(opts) {
    if (opts.key) extraParams.order_key = opts.key;
    pusher()
      .subscribe('private-hibira-order-' + opts.id)
      .bind('status', function (d) {
        var marks = document.querySelectorAll('mark.order-status');
        Array.prototype.forEach.call(marks, function (m) {
          m.textContent = d.label;
        });
        toast(fmt(t.orderStatus, d.label));
      });
  }

  // --- wp-admin ------------------------------------------------------------

  function watchAdmin(channels) {
    channels.forEach(function (name) {
      var ch = pusher().subscribe(name);
      ch.bind('comment-pending', function (d) {
        toast(fmt(t.pending, d.post), [{ label: t.view, href: d.url }]);
      });
      ch.bind('new-order', function (d) {
        toast(fmt(t.newOrder, d.number) + ' · ' + d.total, [{ label: t.view, href: d.url }]);
      });
    });
  }

  window.HibiraWP = {
    pusher: pusher,
    toast: toast,
    watchPost: watchPost,
    watchOrder: watchOrder,
    watchAdmin: watchAdmin,
  };
})();
