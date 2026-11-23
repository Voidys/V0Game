/**
 * Web Push in the settings page (native ES module): registers the service worker (public/sw.js), subscribes or
 * unsubscribes this browser on the toggle button and sends the test notification. Markup: [data-push-box] with the
 * URLs and texts as data attributes, [data-push-toggle], [data-push-status], [data-push-test-box] with [data-push-test].
 * The server stores the subscription as form fields (endpoint, p256dh, auth); the CSRF token is added by csrf.js.
 */

/** base64url public key -> Uint8Array for pushManager.subscribe() */
function keyBytes(base64url) {
  const padded = (base64url + '='.repeat((4 - (base64url.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
  return Uint8Array.from(atob(padded), (char) => char.charCodeAt(0));
}

/** subscription -> the fields the server stores */
function toFields(subscription) {
  const json = subscription.toJSON();
  const body = new URLSearchParams();
  body.set('endpoint', json.endpoint);
  body.set('p256dh', json.keys?.p256dh ?? '');
  body.set('auth', json.keys?.auth ?? '');
  return body;
}

async function post(url, body) {
  const response = await fetch(url, { method: 'POST', body, credentials: 'same-origin' });
  return response.json();
}

function initPushBox(box) {
  const toggle = box.querySelector('[data-push-toggle]');
  const status = box.querySelector('[data-push-status]');
  const text = box.dataset;
  const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  if (!supported) {
    toggle.disabled = true;
    status.textContent = text.unsupported;
    return;
  }

  let registration = null;
  const show = (subscription) => {
    toggle.textContent = subscription ? toggle.dataset.disable : toggle.dataset.enable;
    status.textContent = subscription ? text.on : text.off;
    toggle.dataset.subscribed = subscription ? '1' : '';
  };

  navigator.serviceWorker
    .register(text.swUrl)
    .then((reg) => {
      registration = reg;
      return reg.pushManager.getSubscription();
    })
    .then((subscription) => {
      if (subscription) post(text.subscribeUrl, toFields(subscription)).catch(() => {});
      show(subscription);
    })
    .catch(() => {
      status.textContent = text.failed;
    });

  toggle.addEventListener('click', async () => {
    toggle.disabled = true;
    try {
      registration ??= await navigator.serviceWorker.ready;
      const existing = await registration.pushManager.getSubscription();
      if (existing) {
        await post(text.unsubscribeUrl, new URLSearchParams({ endpoint: existing.endpoint }));
        await existing.unsubscribe();
        show(null);
      } else if ((await Notification.requestPermission()) !== 'granted') {
        status.textContent = text.denied;
      } else {
        const { key } = await (await fetch(text.keyUrl, { credentials: 'same-origin' })).json();
        const subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(key) });
        const result = await post(text.subscribeUrl, toFields(subscription));
        if (result.ok) {
          show(subscription);
        } else {
          await subscription.unsubscribe();
          status.textContent = text.failed;
        }
      }
    } catch {
      status.textContent = text.failed;
    } finally {
      toggle.disabled = false;
    }
  });
}

function initTestBox(box) {
  const button = box.querySelector('[data-push-test]');
  const status = box.querySelector('[data-push-test-status]');
  button.addEventListener('click', async () => {
    button.disabled = true;
    try {
      const result = await post(box.dataset.url, new URLSearchParams());
      status.textContent = result.ok ? box.dataset.sent : box.dataset.none;
    } catch {
      status.textContent = box.dataset.none;
    } finally {
      button.disabled = false;
    }
  });
}

window.onPageInit(() => {
  document.querySelectorAll('[data-push-box]').forEach(initPushBox);
  document.querySelectorAll('[data-push-test-box]').forEach(initTestBox);
});
