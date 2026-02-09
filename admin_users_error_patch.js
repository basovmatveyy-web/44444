// PATCH: show server error messages in toast for role update
(function(){
  var tbl = document.querySelector('#tblUsers tbody');
  if (!tbl) return;
  function showToast(msg, type){
    var toasts = document.getElementById('toasts') || document.body;
    var d = document.createElement('div'); d.className = 'toast '+(type||'info'); d.textContent = msg;
    toasts.appendChild(d); setTimeout(function(){ try{ d.remove(); }catch(e){} }, 4500);
  }
  tbl.addEventListener('click', function(e){
    var b = e.target.closest('button[data-id]'); if(!b) return;
    var id = b.getAttribute('data-id'); var role = b.getAttribute('data-role');
    var fd = new FormData(); fd.append('id', id); fd.append('role', role);
    fetch('admin_api_users.php?action=set_role', {method:'POST', body: fd, credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok){ showToast(j.error || 'Ошибка обновления роли', 'err'); return; }
        showToast('Роль обновлена','ok'); location.reload();
      })
      .catch(function(){ showToast('Ошибка запроса','err'); });
  }, false);
})();