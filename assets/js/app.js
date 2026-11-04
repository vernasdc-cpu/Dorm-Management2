function togglePw(id){const i=document.getElementById(id);i.type=i.type==='password'?'text':'password';}
function openModal(id){document.getElementById(id).classList.add('open');}
function closeModal(id){document.getElementById(id).classList.remove('open');}
function confirmDel(msg){return confirm(msg||'Are you sure?');}
function printArea(){window.print();}
function tableSearch(inputId,tableId){const q=document.getElementById(inputId).value.toLowerCase();document.querySelectorAll('#'+tableId+' tbody tr').forEach(tr=>{tr.style.display=tr.innerText.toLowerCase().includes(q)?'':'none';});}
