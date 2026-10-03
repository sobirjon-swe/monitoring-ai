'use strict';

// Synthetic fixtures only. No network, storage, authentication or model execution.
const projects = [
  {id:'monitoring',name:'Monitoring platformasi',description:'AI monitoring va tahlil tizimi',icon:'monitor',team:['AK','SD','MM'],more:5,status:'progress',days:1,time:'14:32'},
  {id:'lms',name:'Ichki LMS',description:"Xodimlar uchun o'quv platformasi",icon:'school',team:['SN','BK','DL'],more:3,status:'attention',days:5,time:'11:18'},
  {id:'mobile',name:'Mobil ilova',description:'iOS va Android ilovasi',icon:'phone',team:['TM','SH','AZ'],more:4,status:'ontrack',days:11,time:'16:05'},
  {id:'integration',name:"Ma'lumotlar integratsiyasi",description:'Tashqi tizimlar bilan integratsiya',icon:'link',team:['BK','NS','UQ'],more:2,status:'progress',days:21,time:'19:40'},
  ...['Mijozlar portali','Hujjatlar boshqaruvi','Inventar tizimi','Hisobotlar moduli','Rejalashtirish','Xizmatlar katalogi','Ichki bilim bazasi','QA avtomatlashtirish'].map((name,index)=>({id:`demo-${index}`,name,description:'Namuna loyiha',icon:'folder',team:['AK','MM','BK'],more:2,status:['progress','ontrack','attention'][index%3],days:index+2,time:'10:00'}))
];
const signals = [
  {id:'deadline',title:'Vazifa muddati yaqin',project:'monitoring',icon:'calendar',color:'red',time:'2 soat oldin',text:'“Hisobot modulini yakunlash” vazifasining muddati 2 kun ichida tugaydi.',evidence:'DEMO-TASK-104\nNamuna muddat: 05.10.2026\nHolat: tekshiruvga tayyorlash\nManba: lokal synthetic fixture. Asl task tizimidan olinmagan.'},
  {id:'review',title:'Kod tekshiruvi kutilmoqda',project:'lms',icon:'code',color:'purple',time:'5 soat oldin',text:'Yangi commit (a1b2c3d) uchun kod tekshiruvi kutilmoqda.',evidence:'DEMO-REVIEW-28\nNamuna commit: a1b2c3d (haqiqiy Git SHA emas)\nReview: pending\nManba: lokal fixture. Kod yoki GitLab so‘rovi yo‘q.'},
  {id:'coverage',title:"Faollik ma'lumoti yetarli emas",project:'mobile',icon:'chart',color:'gray',time:'1 kun oldin',text:"So'nggi 3 kun davomida qayd etilgan dalillar qamrovi cheklangan. Bu ish bajarilmaganini anglatmaydi.",evidence:'DEMO-COVERAGE-12\nQamrov: qisman\nYetishmaydi: QA va task yangilanishlari\nIsh vaqti yoki xodim samaradorligi haqida xulosa chiqarilmaydi.'},
  ...['QA natijasi kutilmoqda','Talab versiyasini tekshirish','Dalil bog‘lanishini tekshirish','Qamrovni aniqlashtirish'].map((title,index)=>({id:`signal-${index}`,title,project:['integration','monitoring','lms','mobile'][index],icon:'alert',color:'gray',time:'Demo',text:'Namuna signal inson tekshiruvini kutmoqda.',evidence:`DEMO-SIGNAL-${index+4}\nSun‘iy dalil. Haqiqiy source yoki AI natijasi emas.`}))
];
const departments = [{name:'Backend',count:14,icon:'settings',color:'blue'},{name:'Frontend',count:18,icon:'code',color:'purple'},{name:'QA',count:8,icon:'bug',color:'teal'},{name:'PM va BA',count:8,icon:'network',color:'blue'}];
const titles = {overview:['Umumiy holat','Loyihalar va jamoa faoliyati'],projects:['Loyihalar','Namuna loyihalar — faqat lokal ko‘rinish'],employees:['Xodimlar','Demo jamoa tarkibi, reytingsiz'],departments:["Bo'limlar",'Namuna bo‘limlar tarkibi'],signals:['AI signallari','Sun‘iy signallar, inson tekshiruvini kutmoqda'],audit:['Audit jurnali','Demo sahifa — haqiqiy audit bazasi ulanmagan'],settings:['Sozlamalar','Lokal preview imkoniyatlari va chegaralari']};
const $ = id => document.getElementById(id);
const icon = (name,extra='') => `<svg class="icon ${extra}" aria-hidden="true"><use href="#i-${name}"/></svg>`;
const statusLabels = {progress:'Jarayonda',attention:"E'tibor kerak",ontrack:"Reja bo'yicha"};
const state = {section:'overview',period:30,project:'all',search:'',granularity:'daily',tasks:true,code:true};
const referenceDate = new Date('2026-10-03T12:00:00Z');
let dialogTrigger = null;

function dateBefore(days) { const date=new Date(referenceDate);date.setUTCDate(date.getUTCDate()-days);return date; }
function dateText(days) { return dateBefore(days).toLocaleDateString('uz-UZ',{year:'numeric',month:'2-digit',day:'2-digit',timeZone:'UTC'}); }
function filteredProjects() { return projects.filter(project=>project.days<state.period && (state.project==='all'||project.id===state.project) && project.name.toLocaleLowerCase('uz').includes(state.search.toLocaleLowerCase('uz'))); }
function projectRows(rows) {
  return rows.length ? rows.map(project=>`<tr><td><div class="project-name">${icon(project.icon)}<div><strong>${project.name}</strong><small>${project.description}</small></div></div></td><td><div class="team-avatars">${project.team.map(initial=>`<span class="avatar" aria-label="Demo a’zo ${initial}">${initial}</span>`).join('')}<span class="team-more">+${project.more}</span></div></td><td><span class="status ${project.status}"><span class="dot"></span>${statusLabels[project.status]}</span></td><td>${dateText(project.days)} ${project.time}</td><td><button class="more-button" data-project-detail="${project.id}" aria-label="${project.name} tafsiloti">⋮</button></td></tr>`).join('') : '<tr><td colspan="5">Tanlangan filtrga mos demo loyiha yo‘q.</td></tr>';
}
function signalMarkup(signal) {
  const project=projects.find(item=>item.id===signal.project);
  return `<article class="signal"><span class="signal-icon ${signal.color}">${icon(signal.icon)}</span><div class="signal-main"><div class="signal-heading"><h3>${signal.title}</h3><time>${signal.time}</time></div><p class="signal-project">${project.name}</p><p class="signal-text">${signal.text}</p><div class="signal-actions"><button class="evidence-link" data-signal="${signal.id}">${icon('file','tiny')}Dalilni ko'rish<span class="sr-only">: ${signal.title}</span></button><span class="review-badge"><span class="dot"></span>Inson tekshiruvi</span></div></div></article>`;
}
function departmentMarkup(department,index) { return `<button class="department" data-department="${index}"><span class="department-icon ${department.color}">${icon(department.icon)}</span><strong>${department.name}</strong><span class="count">${department.count} nafar</span><span class="capacity" aria-hidden="true"><span style="width:${department.count/48*100}%"></span></span>${icon('chevron')}</button>`; }
function renderChart() {
  let points=Array.from({length:state.period},(_,index)=>{const day=30-state.period+index;const scale=state.project==='all'?1:.45;return {days:state.period-index-1,tasks:Math.round((19+(day%9)*2+Math.sin(day*1.7)*5)*scale),code:Math.round((7+(day%8)*1.4+Math.sin(day*.9)*3)*scale)};});
  if(state.granularity==='weekly'){const weekly=[];for(let index=0;index<points.length;index+=7){const group=points.slice(index,index+7);weekly.push({days:group.at(-1).days,tasks:group.reduce((sum,p)=>sum+p.tasks,0),code:group.reduce((sum,p)=>sum+p.code,0)});}points=weekly;}
  const max=state.granularity==='weekly'?300:50;
  const x=index=>38+index*(735/Math.max(points.length-1,1));
  const y=value=>185-value/max*155;
  const grids=Array.from({length:6},(_,index)=>{const value=index*max/5;return `<line x1="38" y1="${y(value)}" x2="785" y2="${y(value)}" stroke="#e4ebf5"/><text x="23" y="${y(value)+4}" text-anchor="end" fill="#566888" font-size="11">${value}</text>`;}).join('');
  const ticks=points.filter((_,index)=>index%Math.max(1,Math.ceil(points.length/10))===0||index===points.length-1).map(point=>{const index=points.indexOf(point);return `<line x1="${x(index)}" y1="30" x2="${x(index)}" y2="190" stroke="#edf2f8"/><text x="${x(index)}" y="209" text-anchor="middle" fill="#566888" font-size="11">${dateBefore(point.days).toLocaleDateString('uz-UZ',{day:'2-digit',month:'short',timeZone:'UTC'})}</text>`;}).join('');
  const series=(key,color)=>{if(!state[key])return '';const coords=points.map((point,index)=>`${x(index)},${y(point[key])}`).join(' ');return `<polyline points="38,185 ${coords} ${x(points.length-1)},185" fill="${color}" fill-opacity=".025" stroke="none"/><polyline points="${coords}" fill="none" stroke="${color}" stroke-width="2.3"/>${points.map((point,index)=>`<circle cx="${x(index)}" cy="${y(point[key])}" r="2.8" fill="white" stroke="${color}" stroke-width="1.8"><title>${dateText(point.days)}: ${key==='tasks'?'Vazifalar':'Kod o‘zgarishlari'} ${point[key]} (demo)</title></circle>`).join('')}`;};
  $('activity-chart').innerHTML=`<svg viewBox="0 0 810 223" role="img" aria-labelledby="chart-title chart-description"><title id="chart-title">Demo loyihalar faolligi</title><desc id="chart-description">${state.period} kunlik sun‘iy ${state.granularity==='weekly'?'haftalik':'kunlik'} hodisalar. Ish vaqti yoki shaxs reytingi emas.</desc>${grids}${ticks}${series('tasks','#1688ff')}${series('code','#00ac92')}</svg>`;
  $('chart-summary').textContent=points.map(point=>`${dateText(point.days)}: ${point.tasks} vazifa, ${point.code} kod o‘zgarishi (demo)`).join('; ');
}
function renderDashboard() {
  const rows=filteredProjects();
  $('project-rows').innerHTML=projectRows(rows.slice(0,4));
  $('table-status').textContent=`${Math.min(4,rows.length)} / ${rows.length} demo loyiha • ${state.period} kunlik davr`;
  $('project-count').textContent=rows.length;
  const visibleSignals=signals.filter(signal=>state.project==='all'||signal.project===state.project);
  $('signal-list').innerHTML=visibleSignals.slice(0,3).map(signalMarkup).join('')||'<p class="table-status">Bu loyiha uchun demo signal yo‘q.</p>';
  $('department-list').innerHTML=departments.slice(0,3).map(departmentMarkup).join('');
  renderChart();
  if(state.section==='projects') renderSection();
}
function renderSection() {
  const title=titles[state.section][0];
  const description='<p>Demo ma’lumotlar. Backendga ulanmagan; bu sahifa haqiqiy boshqaruv yoki tasdiqlash amali bajarmaydi.</p>';
  let body='';
  if(state.section==='projects') body=`<div class="table-wrap"><table><caption class="sr-only">Barcha filtrlangan demo loyihalar</caption><thead><tr><th>Loyiha</th><th>Jamoa</th><th>Holat</th><th>Oxirgi faollik</th><th>Tafsilot</th></tr></thead><tbody>${projectRows(filteredProjects())}</tbody></table></div><p>Filtrlarni o‘zgartirish uchun Umumiy holatga qayting. Joriy davr: ${state.period} kun.</p>`;
  if(state.section==='signals') body=signals.filter(signal=>state.project==='all'||signal.project===state.project).map(signalMarkup).join('')||'<p>Tanlangan loyiha uchun signal yo‘q.</p>';
  if(state.section==='departments') body=departments.map(departmentMarkup).join('');
  if(state.section==='employees') body=departments.map(department=>`<div class="employee-card"><span class="department-icon">${icon(department.icon)}</span><div><strong>${department.name} jamoasi</strong><p>${department.count} nafar demo a’zo. Shaxsiy baholash yoki reyting yo‘q.</p></div></div>`).join('');
  if(state.section==='audit') body='<div class="notice"><strong>Haqiqiy audit yozuvlari ko‘rsatilmaydi</strong><p>Bu preview hech qanday backend yozuvini o‘zgartirmaydi. Filtr va dialog bosishlari audit bazasiga yuborilmaydi.</p></div>';
  if(state.section==='settings') body='<div class="notice"><strong>Lokal AI inference hali ulanmagan</strong><p>Manba connectorlari, login va ruxsatli APIlar bu mustaqil UI previewga ulanmagan. Davr, qidiruv va dialoglar faqat synthetic fixturelarni ko‘rsatadi. Production frontend stack tanlovi alohida ochiq qaror.</p></div>';
  $('section-view').innerHTML=`<h2>${title}</h2>${description}${body}`;
}
function navigate(section) {
  if(!Object.hasOwn(titles,section))return;
  state.section=section;
  document.querySelectorAll('[data-section]').forEach(button=>{const active=button.dataset.section===section;button.classList.toggle('active',active);if(active)button.setAttribute('aria-current','page');else button.removeAttribute('aria-current');});
  $('page-title').textContent=titles[section][0];$('page-subtitle').textContent=titles[section][1];
  $('dashboard').hidden=section!=='overview';$('section-view').hidden=section==='overview';$('kpis').hidden=section!=='overview';
  if(section!=='overview')renderSection();
  $('main').focus({preventScroll:true});
}
function openDetail(title,description,evidence) {
  dialogTrigger=document.activeElement;
  $('dialog-title').textContent=title;$('dialog-description').textContent=description;$('dialog-evidence').textContent=evidence;
  $('detail-dialog').showModal();
}
document.addEventListener('click',event=>{
  const button=event.target.closest('button');if(!button)return;
  if(button.dataset.section)navigate(button.dataset.section);
  if(button.dataset.openSection)navigate(button.dataset.openSection);
  if(button.dataset.signal){const signal=signals.find(item=>item.id===button.dataset.signal);if(signal)openDetail(signal.title,signal.text,signal.evidence);}
  if(button.dataset.projectDetail){const project=projects.find(item=>item.id===button.dataset.projectDetail);if(project)openDetail(project.name,project.description,`Demo loyiha: ${project.id}\nHolat: ${statusLabels[project.status]}\nOxirgi namuna hodisa: ${dateText(project.days)} ${project.time}\nBu amalda ishlayotgan integratsiya yoki loyiha yozuvi emas.`);}
  if(button.dataset.department!==undefined){const department=departments[Number(button.dataset.department)];if(department)openDetail(department.name+' jamoasi',`${department.count} nafar namuna a’zo.`, 'Jamoa tarkibi synthetic fixture. Ish soati, unumdorlik yoki xodimlar reytingi hisoblanmaydi.');}
});
$('period').addEventListener('change',event=>{state.period=Number(event.target.value);renderDashboard();if(state.section!=='overview')renderSection();});
$('search').addEventListener('input',event=>{state.search=event.target.value.trim();renderDashboard();});
$('project-filter').addEventListener('change',event=>{state.project=event.target.value;renderDashboard();});
$('granularity').addEventListener('change',event=>{state.granularity=event.target.value;renderChart();});
for(const key of ['tasks','code'])$(key+'-toggle').addEventListener('click',()=>{state[key]=!state[key];$(key+'-toggle').setAttribute('aria-pressed',String(state[key]));renderChart();});
$('local-ai').addEventListener('click',()=>openDetail('Lokal AI inference hali ulanmagan','Tasdiqlangan talab: barcha product AI lokal yoki kompaniya boshqaradigan infratuzilmada ishlaydi.','Bu preview model chaqirmaydi.\nLLM, embedding va reranker ulanmagan.\nSignal va grafiklar — sun‘iy demo ma’lumotlar.\nHech qanday tashqi AI API, telemetry yoki cloud fallback yo‘q.'));
$('profile').addEventListener('click',()=>openDetail('Demo administrator','Azizbek Karimov — namuna profil.','Haqiqiy login yoki token ishlatilmaydi.\nBu tugma access grant yoki administrator vakolati bermaydi.'));
$('close-dialog').addEventListener('click',()=>$('detail-dialog').close());
$('detail-dialog').addEventListener('close',()=>{if(dialogTrigger?.isConnected)dialogTrigger.focus();});
$('detail-dialog').addEventListener('click',event=>{if(event.target!==$('detail-dialog'))return;const rect=event.target.getBoundingClientRect();if(event.clientX<rect.left||event.clientX>rect.right||event.clientY<rect.top||event.clientY>rect.bottom)event.target.close();});
document.querySelector('.brand').addEventListener('click',event=>{event.preventDefault();navigate('overview');});
$('project-filter').insertAdjacentHTML('beforeend', projects.map(project=>`<option value="${project.id}">${project.name}</option>`).join(''));
renderDashboard();
