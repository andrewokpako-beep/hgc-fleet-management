(function(){
  let nextId=0;
  const selector='select:not([multiple]):not([size]):not([data-no-search])';
  const textOf=o=>(o.label||o.textContent||'').trim();
  function enhance(select){
    if(select.dataset.searchEnhanced==='1'||!select.parentNode)return;
    select.dataset.searchEnhanced='1';
    const wrapper=document.createElement('div');wrapper.className='select-search';
    const input=document.createElement('input');input.type='search';input.className='select-search-input';input.autocomplete='off';input.spellcheck=false;input.setAttribute('role','combobox');input.setAttribute('aria-autocomplete','list');input.setAttribute('aria-haspopup','listbox');input.setAttribute('aria-expanded','false');input.setAttribute('aria-label',select.getAttribute('aria-label')||select.labels?.[0]?.textContent?.trim()||'Search options');
    const list=document.createElement('div');list.className='select-search-list';list.id='select-search-list-'+(++nextId);list.setAttribute('role','listbox');list.hidden=true;input.setAttribute('aria-controls',list.id);
    select.parentNode.insertBefore(wrapper,select);wrapper.append(input,list);wrapper.append(select);select.classList.add('select-search-native');
    const wasRequired=select.required;select.required=false;input.required=wasRequired;
    let active=-1,visible=[];
    function sync(){const opt=select.options[select.selectedIndex];input.value=opt?textOf(opt):'';input.disabled=select.disabled;input.required=select.required||wasRequired;input.setCustomValidity('');wrapper.classList.toggle('is-disabled',select.disabled)}
    function close(restore=true){list.hidden=true;input.setAttribute('aria-expanded','false');active=-1;if(restore)sync()}
    function render(query=''){
      const q=query.trim().toLocaleLowerCase();visible=Array.from(select.options).map((o,index)=>({o,index,label:textOf(o)})).filter(x=>!x.o.hidden&&(!q||x.label.toLocaleLowerCase().includes(q)));
      list.replaceChildren();active=-1;
      if(!visible.length){const empty=document.createElement('div');empty.className='select-search-empty';empty.textContent='No matching options';list.append(empty)}
      visible.forEach((x,i)=>{const item=document.createElement('button');item.type='button';item.className='select-search-option';item.setAttribute('role','option');item.id=list.id+'-option-'+i;item.setAttribute('aria-selected',String(x.o.value===select.value));item.textContent=x.label||'—';item.disabled=x.o.disabled;item.addEventListener('mousedown',e=>e.preventDefault());item.addEventListener('click',()=>{select.selectedIndex=x.index;select.dispatchEvent(new Event('input',{bubbles:true}));select.dispatchEvent(new Event('change',{bubbles:true}));close();input.focus()});list.append(item)});
    }
    function open(query=''){if(input.disabled)return;render(query);list.hidden=false;input.setAttribute('aria-expanded','true')}
    function move(delta){if(list.hidden)open(input.value);const items=Array.from(list.querySelectorAll('.select-search-option:not(:disabled)'));if(!items.length)return;active=(active+delta+items.length)%items.length;items.forEach((x,i)=>x.setAttribute('aria-selected',String(i===active)));input.setAttribute('aria-activedescendant',items[active].id);items[active].scrollIntoView({block:'nearest'})}
    input.addEventListener('focus',()=>{input.value='';open('')});
    input.addEventListener('input',()=>{input.setCustomValidity(input.value===textOf(select.options[select.selectedIndex]||{textContent:''})?'':'Choose an option from the list.');open(input.value)});
    input.addEventListener('keydown',e=>{if(e.key==='ArrowDown'){e.preventDefault();move(1)}else if(e.key==='ArrowUp'){e.preventDefault();move(-1)}else if(e.key==='Enter'&&!list.hidden){e.preventDefault();const items=list.querySelectorAll('.select-search-option:not(:disabled)');if(active>=0&&items[active])items[active].click();else if(items.length===1)items[0].click()}else if(e.key==='Escape'){close();input.blur()}else if(e.key==='Tab')close()});
    select.addEventListener('change',sync);select.addEventListener('input',sync);select.form?.addEventListener('reset',()=>setTimeout(sync,0));
    new MutationObserver(()=>{render(input.value);sync()}).observe(select,{subtree:true,childList:true,attributes:true,attributeFilter:['disabled','hidden','label','value']});sync();
  }
  function scan(root=document){if(root.matches?.(selector))enhance(root);root.querySelectorAll?.(selector).forEach(enhance)}
  function start(){scan();new MutationObserver(records=>records.forEach(r=>r.addedNodes.forEach(n=>{if(n.nodeType===1)scan(n)}))).observe(document.body,{childList:true,subtree:true})}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
})();
