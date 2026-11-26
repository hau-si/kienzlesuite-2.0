// GOÄ-Rechner (gemeinsam für Kassenseite und Adminpanel): Einfachsatz + Endbetrag → Faktor und Preis.
function parseCents(value) {
  const v=String(value||'').trim().replace(/\s|€/g,'').replace(/\.(?=\d{3}(\D|$))/g,'').replace(',','.');
  if(!/^\d+(\.\d{1,2})?$/.test(v)) return null;
  return Math.round(Number(v)*100);
}
// Faktor in Zehntausendsteln (23000 = 2,3): bis zu 4 Nachkommastellen, damit Endbeträge immer exakt einstellbar sind.
// Mit Punktzahl (aus dem GOÄ-Verzeichnis) genau nach § 5 GOÄ: Punktzahl × 5,82873 Cent × Faktor, erst am Ende gerundet.
const feeExact=(punkte,u)=>Math.floor((punkte*582873*u+5e8)/1e9);
const fee=(base,u,punkte)=>punkte?feeExact(punkte,u):Math.floor((base*u+5000)/10000);
const commaFactor=u=>(u/10000).toFixed(4).replace(/0+$/,'').replace(/\.$/,'').replace('.',',');
const factorUnits=text=>{ const [w,f='']=String(text).replace(',','.').split('.'); return Number(w)*10000+Number(f.padEnd(4,'0').slice(0,4)); };
function bestFactor(base,target,punkte) {
  const ideal=target*10000/base; let best=null;
  for(const step of [100,10,1]) {                 // 2, 3, dann 4 Nachkommastellen
    const center=Math.round(ideal/step)*step; let exact=null;
    for(let u=center-4*step; u<=center+4*step; u+=step) {
      const unit=Math.max(10000,u), p=fee(base,unit,punkte);
      if(p===target && (!exact || Math.abs(unit-ideal)<Math.abs(exact[0]-ideal))) exact=[unit,p];
      if(!best || Math.abs(p-target)<Math.abs(best[1]-target) || (Math.abs(p-target)===Math.abs(best[1]-target) && Math.abs(unit-ideal)<Math.abs(best[0]-ideal))) best=[unit,p];
    }
    if(exact) { best=exact; break; }
  }
  return best;
}

// ---- GOÄ-Verzeichnis: Ziffer oder Stichwort → Leistungstext, Punktzahl, Einfachsatz, Gebührenrahmen ----------------
// Daten: amtliches Gebührenverzeichnis der GOÄ (goae-verzeichnis.json, erzeugt von scripts/goae-verzeichnis.py).
const GOAE_URL=(()=>{ try { const u=new URL(document.currentScript.src); return new URL('goae-verzeichnis.json',u).href+u.search; } catch { return 'assets/goae-verzeichnis.json'; } })();
const GOAE_RAHMEN={standard:'ärztlich',technical:'technisch (A, E, O)',lab:'Labor (M / Nr. 437)'};
let goaeDaten=null, goaeLaden=null;
function goaeVerzeichnis() {
  if(!goaeLaden) goaeLaden=fetch(GOAE_URL,{credentials:'same-origin'}).then(r=>{ if(!r.ok) throw new Error('GOÄ-Verzeichnis konnte nicht geladen werden.'); return r.json(); }).then(d=>{
    const liste=d.eintraege.map(e=>({nr:e[0],abschnitt:e[1],punkte:e[2],cent:e[3],rahmen:e[4],text:e[5],einfach:!!e[6],suche:(e[0]+' '+e[5]).toLowerCase()}));
    goaeDaten={stand:d.stand,liste,map:new Map(liste.map(e=>[e.nr.toLowerCase(),e]))}; return goaeDaten;
  }).catch(e=>{ goaeLaden=null; throw e; });
  return goaeLaden;
}
const goaeNorm=v=>String(v||'').trim().toLowerCase().replace(/^go(ä|ae)\s*/,'').replace(/\s+/g,'');
function goaeEintrag(code) { return goaeDaten ? goaeDaten.map.get(goaeNorm(code))||null : null; }
// Punktzahl, wenn Ziffer und Einfachsatz zum Verzeichnis passen (sonst 0: dann gilt Einfachsatz × Faktor).
function goaePunkte(code,base) { const e=goaeEintrag(code); return e && e.cent===base ? e.punkte : 0; }
function goaeSuche(text,max) {
  const v=String(text||'').trim().toLowerCase(); if(!goaeDaten || !v) return [];
  const worte=v.split(/\s+/), treffer=[];
  for(const e of goaeDaten.liste) if(worte.every(w=>e.suche.includes(w))) treffer.push([e.nr.toLowerCase().startsWith(v)?0:e.text.toLowerCase().startsWith(worte[0])?1:2,e]);
  return treffer.sort((a,b)=>a[0]-b[0]).slice(0,max||8).map(t=>t[1]);
}
const goaeEuro=c=>(c/100).toLocaleString('de-DE',{style:'currency',currency:'EUR'});
function goaeKurz(text,max) { if(text.length<=max) return text; const t=text.slice(0,max-1); return t.slice(0,Math.max(t.lastIndexOf(' '),max-30)).replace(/[\s,;:-]+$/,'')+' …'; }
// Startfaktor beim Übernehmen: Schwellenwert des Rahmens (2,3 / 1,8 / 1,15), Zuschläge nur 1,0-fach.
function goaeStartfaktor(e,feeTypes) { return e.einfach ? '1' : commaFactor(feeTypes[e.rahmen].threshold*100); }
/**
 * Hängt unter das Ziffernfeld einen Vorschlag: bei bekannter Ziffer Leistungstext mit „Übernehmen“,
 * sonst eine Trefferliste zur Suche nach Ziffer oder Stichwort. onPick(eintrag) füllt das Formular.
 */
function goaeHelfer(input,onPick) {
  const box=document.createElement('div'); box.className='goae-hint'; box.hidden=true; box.setAttribute('aria-live','polite');
  input.after(box); input.setAttribute('autocomplete','off');
  if(!input.placeholder || input.placeholder==='z. B. 250') input.placeholder='Ziffer oder Stichwort, z. B. 250 oder Blutentnahme';
  input.maxLength=60;
  const node=(tag,text,cls)=>{ const n=document.createElement(tag); if(text) n.textContent=text; if(cls) n.className=cls; return n; };
  const zeile=e=>e.nr+' · '+goaeKurz(e.text,110)+' · '+goaeEuro(e.cent)+(e.einfach?' (nur 1,0-fach)':'');
  const pick=e=>{ input.value=e.nr; onPick(e); render(); };
  let timer=null;
  async function render() {
    const v=input.value.trim(); if(!v) { box.hidden=true; box.replaceChildren(); return; }
    try { await goaeVerzeichnis(); } catch(err) { box.hidden=false; box.replaceChildren(node('p',err.message,'muted small')); return; }
    if(input.value.trim()!==v) return;
    const e=goaeEintrag(v); box.replaceChildren();
    if(e) {
      box.append(node('p','GOÄ '+e.nr+' (Abschnitt '+e.abschnitt+'): '+e.text,'goae-hint-text'),
        node('p',e.punkte+' Punkte · Einfachsatz '+goaeEuro(e.cent)+' · Rahmen '+GOAE_RAHMEN[e.rahmen]+(e.einfach?' · Zuschlag, nur mit dem einfachen Satz':''),'muted small'));
      const b=node('button','Einfachsatz und Rahmen übernehmen','secondary small-button'); b.type='button'; b.onclick=()=>pick(e); box.append(b);
    } else {
      const liste=goaeSuche(v,8);
      if(!liste.length) box.append(node('p',/^\d/.test(v)?'Keine GOÄ-Ziffer „'+v+'“ im Gebührenverzeichnis (Analogziffern bitte von Hand eintragen).':'Kein Treffer im GOÄ-Verzeichnis.','muted small'));
      for(const t of liste) { const b=node('button',zeile(t),'goae-hint-item'); b.type='button'; b.onclick=()=>pick(t); box.append(b); }
    }
    box.append(node('p','Quelle: amtliches Gebührenverzeichnis der GOÄ, Punktwert 5,82873 Cent. Analogziffern und Abrechnungsempfehlungen sind nicht enthalten.','muted small goae-hint-source'));
    box.hidden=false;
  }
  input.addEventListener('input',()=>{ clearTimeout(timer); timer=setTimeout(render,150); });
  input.addEventListener('focus',()=>{ goaeVerzeichnis().catch(()=>{}); });
  return {render,hide:()=>{ box.hidden=true; box.replaceChildren(); }};
}
// Beim Speichern: Ein Stichwort ohne Ziffer (z. B. „Blutentnahme“) darf nicht als GOÄ-Ziffer gespeichert werden.
function goaePruefen(code) {
  const v=String(code||'').trim();
  if(v && !/\d/.test(v) && goaeDaten && !goaeEintrag(v)) throw new Error('„'+v+'“ ist keine GOÄ-Ziffer. Bitte einen Treffer aus der Liste unter dem Feld wählen oder die Ziffer eintragen.');
}
