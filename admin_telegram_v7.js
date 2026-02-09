// admin_telegram_v7.js — shim / utilities
(function(){
  window.TG = window.TG || {};
  TG.$ = function(s,r){ return (r||document).querySelector(s); };
  TG.$$ = function(s,r){ return Array.from((r||document).querySelectorAll(s)); };
  TG.toast = function(m,t){
    if (typeof window.showToast === "function") { window.showToast(m,t||"info"); }
    else { console[(t==="error")?"error":"log"]("[toast:"+ (t||"info") +"]", m); }
  };
  TG.json = async function(url, data){
    const opt = { headers:{'Accept':'application/json'} };
    if (data !== undefined) {
      opt.method = "POST";
      opt.headers['Content-Type'] = 'application/json';
      opt.body = JSON.stringify(data);
    }
    const resp = await fetch(url, opt);
    return await resp.json();
  };
  TG.setBadge = function(el, ok, okText, badText){
    if(!el) return;
    el.textContent = ok ? okText : badText;
    el.classList.remove('badge--ok','badge--bad');
    el.classList.add(ok ? 'badge--ok' : 'badge--bad');
  };
})();