
(function(global){
 function inline(parent,text){
  text.split(/(\*\*[^*]+\*\*|__[^_]+__|\*[^*\n]+\*|`[^`]+`)/g).forEach(part=>{
   if(/^(\*\*|__)/.test(part)){const b=document.createElement('strong');b.textContent=part.slice(2,-2);parent.appendChild(b);}
   else parent.appendChild(document.createTextNode(/^(\*|`)/.test(part)?part.slice(1,-1):part));
  });
 }
 global.renderCoachResponse=function(target,answer,mode){
  target.replaceChildren();
  if(mode==='assistant'||mode==='patterns'){
   const heading=document.createElement('h3');heading.className='coach-result-title';heading.textContent=mode==='patterns'?'Your pattern summary':'Your assistant';target.appendChild(heading);
   const card=document.createElement('article');card.className='coach-assistant-card';target.appendChild(card);
   const head=document.createElement('div');head.className='coach-assistant-head';card.appendChild(head);
   const mark=document.createElement('span');mark.className='coach-assistant-mark';mark.setAttribute('aria-hidden','true');mark.textContent='✦';head.appendChild(mark);
   const intro=document.createElement('div');intro.className='coach-assistant-intro';head.appendChild(intro);
   const label=document.createElement('span');label.className='coach-assistant-label';label.textContent=mode==='patterns'?'STUDY HABIT SUMMARY':'PERSONALIZED RESPONSE';intro.appendChild(label);
   const subtitle=document.createElement('span');subtitle.className='coach-assistant-subtitle';subtitle.textContent=mode==='patterns'?'A clear overall trend and a useful next step':'A thoughtful next step, based on your study records';intro.appendChild(subtitle);
   const message=document.createElement('div');message.className='coach-assistant-message';
   card.appendChild(message);
   String(answer||'').trim().split(/\n\s*\n/).filter(Boolean).forEach(paragraph=>{const p=document.createElement('p');inline(p,paragraph.trim().replace(/\s*\n\s*/g,' '));message.appendChild(p);});
   return;
  }
  const heading=document.createElement('h3');heading.className='coach-result-title';heading.textContent=mode==='schedule'?'Your weekly study plan':'Your next steps';target.appendChild(heading);
  const body=document.createElement('div');body.className='coach-result-grid'+(mode==='schedule'?' coach-schedule-grid':'');target.appendChild(body);
  let section=null,list=null,task=null,taskCount=0;
  function taskCard(title){
   taskCount++;list=null;
   task=document.createElement('article');task.className='coach-task';body.appendChild(task);
   const top=document.createElement('div');top.className='coach-task-head';task.appendChild(top);
   const rank=document.createElement('span');rank.className='coach-task-rank';rank.textContent=String(taskCount).padStart(2,'0');top.appendChild(rank);
   const names=document.createElement('div');top.appendChild(names);
   const label=document.createElement('span');label.className='coach-task-label';label.textContent=taskCount===1?'START HERE':'UP NEXT';names.appendChild(label);
   const name=document.createElement('h4');name.textContent=title.replace(/\s*\(ID:\s*\d+\)\s*$/i,'');names.appendChild(name);
  }
  function card(title){section=document.createElement('section');section.className='coach-result-card'+(mode==='schedule'?' coach-day-card':'');body.appendChild(section);if(title){const h=document.createElement('h4');inline(h,title);section.appendChild(h);}list=null;}
  String(answer||'').split(/\r?\n/).forEach(raw=>{
   const line=raw.trim();if(!line||/^```|^[-*_]{3,}$/.test(line)){list=null;return;}
   const title=line.match(/^#{1,6}\s+(.+)$/)||line.match(/^(?:[-*]\s+)?\*\*([^*]+)\*\*:?$/);
   const clean=line.replace(/^#{1,6}\s+/,'').replace(/^(?:[-*?]|\d+[.)])\s+/,'').replace(/\*\*|__/g,'');
   const taskTitle=clean.match(/^(.+?\(ID:\s*\d+\))\s*$/i);
   if(taskTitle){taskCard(taskTitle[1]);return;}
   const field=clean.match(/^(Due(?: date)?|Reason(?: for order)?|Difficulty|Estimated effort|First step):\s*(.+)$/i);
   if(task&&field){
    const key=field[1].toLowerCase(),value=field[2];
    const row=document.createElement('div');
    row.className=key.startsWith('due')?'coach-task-due':key==='first step'?'coach-task-action':'coach-task-detail';
    const label=document.createElement('span');label.textContent=key==='first step'?'Your first move':key.startsWith('reason')?'Why this task':field[1];row.appendChild(label);
    const text=document.createElement('p');inline(text,value);row.appendChild(text);task.appendChild(row);return;
   }
   if(title){task=null;const day=mode==='schedule'?title[1].match(/^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday),\s*(.+)$/i):null;card(day?day[1]:title[1]);if(day&&section){section.dataset.weekday=day[1];const stamp=document.createElement('span');stamp.className='coach-day-date';stamp.textContent=day[2];section.appendChild(stamp);}return;}if(!section)card('');
   const item=line.match(/^(?:[-*?]|\d+[.)])\s+(.+)$/);
   if(item){if(mode==='schedule'){const focus=item[1].match(/^Focus:\s*(.+)$/i);if(focus){const block=document.createElement('div');block.className='coach-day-focus';const label=document.createElement('span');label.textContent='FOCUS';const text=document.createElement('p');inline(text,focus[1]);block.append(label,text);section.appendChild(block);list=null;return;}if(/^Optional buffer\s*\/\s*rest/i.test(item[1]))section.classList.add('coach-day-buffer');}if(!list){list=document.createElement('ul');section.appendChild(list);}const li=document.createElement('li');inline(li,item[1]);if(mode==='schedule'&&/^Break:/i.test(item[1]))li.className='coach-day-break';if(mode==='schedule'&&/^Total planned:/i.test(item[1]))li.className='coach-day-total';list.appendChild(li);}
   else{list=null;const p=document.createElement('p');inline(p,line);section.appendChild(p);}
  });
  const note=document.createElement('p');note.className='coach-result-note';note.textContent=mode==='schedule'?'This is a suggested plan. Adjust focus and break durations to suit you.':'Choose one action to start. You can adjust the pace anytime.';target.appendChild(note);
 };
})(window);
