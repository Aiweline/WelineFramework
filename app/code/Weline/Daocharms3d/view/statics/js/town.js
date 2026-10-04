const townAssetUrl = new URL(import.meta.url);
const threeAssetUrl = new URL('./vendor/three.module.js', townAssetUrl);
threeAssetUrl.search = townAssetUrl.search;
const THREE = await import(threeAssetUrl.href);
export async function mount(root){
 const nodes=[...root.querySelectorAll('[data-town-product]')];
 const products=Object.fromEntries(nodes.map(el=>[el.dataset.townProduct,{id:Number(el.dataset.townProduct),name:el.dataset.name,shop:el.dataset.name,price:el.dataset.price,image:el.dataset.image,url:el.dataset.url,element:el,position:[0,1.65,-4]}]));
 const portals={accountHome:{kind:'portal',name:root.dataset.accountLabel,url:root.dataset.accountUrl,position:[-8,1.8,12.23]},cartOffice:{kind:'portal',name:root.dataset.cartLabel,url:root.dataset.cartUrl,position:[11,1.8,12.23]},checkoutOffice:{kind:'portal',name:root.dataset.checkoutLabel,url:root.dataset.checkoutUrl,position:[-17,1.8,10.23]}};
 const destinations={...products,...portals};
 const rows=Math.max(1,Math.min(8,Math.ceil(nodes.length/3)));
 let navigateShop=()=>{},inspectNearby=()=>{},openService=()=>location.assign(root.dataset.accountUrl),timer;
 function notice(text){const el=root.querySelector('#notice');el.textContent=text;el.hidden=false;clearTimeout(timer);timer=setTimeout(()=>el.hidden=true,3200);}
 async function openShop(key){const p=destinations[key];if(!p)return;if(p.kind==='portal'){location.assign(p.url);return;}try{await Weline.load('cart');const button=p.element.querySelector('[data-town-purchase]');WelineCartPurchaseActions.bindPurchaseButton(button);button.click();}catch(error){notice(root.dataset.requestError);console.error('[DaoCharms3d purchase]',error);}}
 root.querySelector('#inspect').onclick=()=>inspectNearby();
 root.querySelectorAll('[data-travel]').forEach(el=>el.onclick=()=>navigateShop(el.dataset.travel));
 const main=document.querySelector('#account-auth-content')||document.querySelector('main');
 const authStage=main?.closest('.account-auth-stage');
 const operationHost=authStage?.closest('main')||authStage||main;
 if(operationHost){operationHost.classList.add('daocharms-town-operation');if(/\/(?:customer|account|cart|checkout)(?:\/|$)/.test(location.pathname)){document.body.classList.add('daocharms-town-service-page');const workspace=document.createElement('div');workspace.className='daocharms-town-service';operationHost.parentNode.insertBefore(workspace,operationHost);workspace.append(root,operationHost);}else operationHost.parentNode.insertBefore(root,operationHost);}
 root.querySelector('[data-town-operation]').onclick=()=>{if(document.body.classList.contains('daocharms-town-service-page')){root.scrollIntoView({behavior:'smooth',block:'start'});main?.setAttribute('tabindex','-1');main?.focus({preventScroll:true});}else openService();};
 root.querySelector('[data-town-search]').addEventListener('submit',async event=>{event.preventDefault();const q=new FormData(event.currentTarget).get('q');const out=root.querySelector('[data-town-results]');out.replaceChildren();try{await Weline.load('api');const response=await Weline.Api.resource('search').search({q,type:'product',area:'frontend'});const result=response?.data||response;for(const hit of result.hits||[]){const id=String(hit.product_id||hit.payload?.product_id||hit.entity_id||'');const link=document.createElement('a');link.className='w-button';link.textContent=hit.title;const href=products[id]?.url||hit.url;if(!href)continue;const url=new URL(href,new URL(root.dataset.storefrontUrl,location.origin));if(!['http:','https:'].includes(url.protocol))continue;link.href=url.href;if(products[id])link.addEventListener('click',e=>{e.preventDefault();navigateShop(id);});out.append(link);}if(!out.childElementCount)out.textContent=root.dataset.emptyLabel;}catch(error){out.textContent=root.dataset.requestError;notice(root.dataset.requestError);console.error("[DaoCharms3d search]",error);}});
const reduced=matchMedia('(prefers-reduced-motion: reduce)').matches;
const rootStyle=getComputedStyle(document.documentElement);
const aliases={'night-sky':'sky','day-sky':'day-sky','fog':'sky','cream':'stone','gold':'gold','land':'land','green':'green','silk':'silk','lamp':'lantern'};
const color=name=>rootStyle.getPropertyValue('--daocharms-town-'+(aliases[name]||name)).trim()||rootStyle.getPropertyValue('--color-primary').trim()||rootStyle.color;
const host=root.querySelector('#world');
let renderer;
try{renderer=new THREE.WebGLRenderer({antialias:true,alpha:false,powerPreference:'low-power'});}catch(e){const status=root.querySelector('[data-town-status]');status.textContent=root.dataset.fallback;status.hidden=false;}
if(renderer){
renderer.setPixelRatio(Math.min(devicePixelRatio,1.6));
renderer.shadowMap.enabled=true;
renderer.shadowMap.type=THREE.PCFSoftShadowMap;
renderer.outputColorSpace=THREE.SRGBColorSpace;
renderer.toneMapping=THREE.ACESFilmicToneMapping;
renderer.toneMappingExposure=1.35;
renderer.domElement.tabIndex=0;renderer.domElement.setAttribute("aria-label",root.querySelector("#world").getAttribute("aria-label"));host.appendChild(renderer.domElement);
const scene=new THREE.Scene();
scene.background=new THREE.Color(color('night-sky'));
scene.fog=new THREE.FogExp2(color('fog'),.012);
const camera=new THREE.PerspectiveCamera(65,1,.08,180);
let yaw=0,pitch=-.03,route=[],routeKey=null,nearby=null;
const keys=new Set(),colliders=[];
function aim(x,y,z){const d=new THREE.Vector3(x,y,z).sub(camera.position);yaw=Math.atan2(-d.x,-d.z);pitch=Math.atan2(d.y,Math.hypot(d.x,d.z));}
function reset(){route=[];routeKey=null;camera.position.set(4,1.95,8);yaw=0;pitch=-.03;root.querySelector('#arrival').hidden=false;setTimeout(()=>root.querySelector('#arrival').hidden=true,6500);}
reset();
const ambient=new THREE.HemisphereLight(color('cream'),color('wood'),1.1);scene.add(ambient);
const sun=new THREE.DirectionalLight(color('lamp'),1.5);sun.position.set(-15,28,12);sun.castShadow=true;sun.shadow.mapSize.set(2048,2048);sun.shadow.camera.left=-28;sun.shadow.camera.right=28;sun.shadow.camera.top=28;sun.shadow.camera.bottom=-28;sun.shadow.normalBias=.04;scene.add(sun);
const materialCache=new Map();
function mat(key,opts={}){const id=key+JSON.stringify(opts);if(!materialCache.has(id))materialCache.set(id,new THREE.MeshStandardMaterial({color:color(key)||key,roughness:.85,...opts}));return materialCache.get(id);}
const wood=mat('wood'),wall=mat('wall'),roof=mat('roof'),land=mat('land'),water=mat('water',{metalness:.35,roughness:.28}),green=mat('green');
const stone=mat('cream'),gold=mat('gold');
const unitBox=new THREE.BoxGeometry(1,1,1);
function box(parent,x,y,z,w,h,d,m){const o=new THREE.Mesh(unitBox,m);o.position.set(x,y,z);o.scale.set(w,h,d);o.castShadow=true;o.receiveShadow=true;parent.add(o);return o;}
function cylinder(parent,x,y,z,r,h,m,vertices=8){const o=new THREE.Mesh(new THREE.CylinderGeometry(r,r,h,vertices),m);o.position.set(x,y,z);o.castShadow=true;parent.add(o);return o;}
function sphere(parent,x,y,z,r,m){const o=new THREE.Mesh(new THREE.IcosahedronGeometry(r,1),m);o.position.set(x,y,z);o.castShadow=true;parent.add(o);return o;}
const town=new THREE.Group();scene.add(town);
// 河流穿镇而过；青石岸与桥是明确的空间方向线索。
box(town,0,-.35,-Math.max(0,rows-2)*4,42,.7,Math.max(30,rows*8+24),land);
box(town,0,.02,0,43,.08,4.6,water);
for(const z of [-2.6,2.6]){box(town,0,.2,z,42,.5,.6,stone);box(town,0,.04,z>0?4.4:-4.4,42,.12,3,mat('wall'));}
for(let x=-20;x<=20;x+=1.6)for(const z of [-3.3,3.3])box(town,x,.14,z,1.4,.06,.58,mat('land'));
const ripples=[];
for(let i=0;i<35;i++){const x=(i*7.13%39)-19.5,z=(i*1.77%4)-2;const r=box(town,x,.08,z,.3+i%4*.16,.01,.025,mat('cream',{transparent:true,opacity:.3}));r.castShadow=false;ripples.push(r);}
function bridge(x){
  for(let i=0;i<14;i++){const z=-3.25+i*.5,y=.4+Math.sin(i/13*Math.PI)*1.05;box(town,x,y,z,3.4,.3,.55,stone);
    for(const side of [-1,1]){box(town,x+side*1.7,y+.55,z,.12,1,.13,stone);if(i<13){const rail=box(town,x+side*1.7,y+1.04,z+.25,.12,.14,.62,stone);rail.rotation.x=-(Math.cos(i/13*Math.PI)*.23);}}}
}
bridge(4);bridge(-13);
const lampMaterial=mat('lamp',{emissive:color('lamp'),emissiveIntensity:1.1});
function lantern(parent,x,y,z,size=.38){
  const l=new THREE.Mesh(new THREE.SphereGeometry(size,8,6),lampMaterial);l.scale.y=1.2;l.position.set(x,y,z);parent.add(l);
  cylinder(parent,x,y+size*1.2,z,size*.55,.09,wood);
  cylinder(parent,x,y-size*1.2,z,size*.55,.09,gold);
  box(parent,x,y-size*1.8,z,.035,size,.035,gold);
}
function roofMesh(parent,w,d,y){
  const s=new THREE.Shape();s.moveTo(-d/2,.22);s.lineTo(-d*.43,0);s.lineTo(-d*.18,.64);s.lineTo(0,1.25);s.lineTo(d*.18,.64);s.lineTo(d*.43,0);s.lineTo(d/2,.22);s.lineTo(d/2,.08);s.lineTo(d*.43,-.16);s.lineTo(0,1.07);s.lineTo(-d*.43,-.16);s.lineTo(-d/2,.08);s.closePath();
  const geom=new THREE.ExtrudeGeometry(s,{depth:w,bevelEnabled:false});geom.rotateY(Math.PI/2);
  const o=new THREE.Mesh(geom,roof);o.position.set(-w/2,y,0);o.castShadow=true;parent.add(o);
  box(parent,0,y+1.27,0,w+.25,.14,.14,roof);
  for(let x=-w/2;x<w/2;x+=.32){for(const sign of [-1,1]){const strip=box(parent,x,y+.53,sign*d*.24,.06,.07,d*.56,roof);strip.rotation.x=sign*.47;}}
  for(const x of [-w/2,w/2])for(const sign of [-1,1])sphere(parent,x,y+.2,sign*d*.49,.11,roof);
}
const merchandise=[],photoFaces=[];
function addMerchandise(parent,key,z){
 const p=products[key],g=new THREE.Group();g.position.set(0,1.5,z);g.userData.product=key;parent.add(g);merchandise.push(g);
 const sides=p.id===7?8:48,shape=new THREE.Shape();
 if(p.id===8){shape.moveTo(-.2,-.32);shape.bezierCurveTo(-.5,-.15,-.44,.08,-.19,.1);shape.bezierCurveTo(-.35,.48,.2,.56,.3,.24);shape.bezierCurveTo(.55,.08,.44,-.19,.2,-.25);shape.lineTo(.08,-.43);shape.closePath();}
 else {for(let i=0;i<sides;i++){const a=i/sides*Math.PI*2;const x=Math.cos(a)*.4,y=Math.sin(a)*.4;i?shape.lineTo(x,y):shape.moveTo(x,y);}shape.closePath();}
 const geometry=new THREE.ExtrudeGeometry(shape,{depth:.12,bevelEnabled:true,bevelSize:.02,bevelThickness:.02,bevelSegments:1});
 const body=new THREE.Mesh(geometry,mat('wood',{metalness:.15,roughness:.28}));g.add(body);
 const cord=new THREE.Mesh(new THREE.TorusGeometry(.16,.018,6,24),wood);cord.position.set(0,.54,0);g.add(cord);
 for(const y of [.62,.78])sphere(g,0,y,0,.08,wood);
 if(p.image){const uv=geometry.attributes.uv,pos=geometry.attributes.position;for(let i=0;i<uv.count;i++)uv.setXY(i,(pos.getX(i)+.55)/1.1,(pos.getY(i)+.55)/1.1);const material=new THREE.MeshStandardMaterial({color:color('wood'),roughness:.6});const face=new THREE.Mesh(geometry,material);face.position.z=.002;g.add(face);photoFaces.push({face,p,state:'idle',generation:0});}
 box(g,0,-.58,0,1.2,.12,.8,wood);
 const c=document.createElement('canvas');c.width=512;c.height=192;const ctx=c.getContext('2d');ctx.fillStyle=color('cream');ctx.fillRect(0,0,512,192);ctx.fillStyle=color('wood');ctx.font='38px serif';ctx.textAlign='center';ctx.fillText(p.name,256,65);ctx.fillText(p.price,256,128);const texture=new THREE.CanvasTexture(c);const sign=new THREE.Mesh(new THREE.PlaneGeometry(1.4,.5),new THREE.MeshBasicMaterial({map:texture,toneMapped:false}));sign.position.set(0,-.75,.44);g.add(sign);
}
function addMerchant(parent,x,z,key){const g=new THREE.Group();g.position.set(x,0,z);parent.add(g);const robe=mat('green');const body=new THREE.Mesh(new THREE.CylinderGeometry(.17,.28,.9,8),robe);body.position.y=.65;g.add(body);sphere(g,0,1.38,0,.18,mat('wall'));sphere(g,0,1.48,-.04,.18,wood);for(const x of [-.22,.22]){const arm=box(g,x,.92,.08,.13,.43,.13,robe);arm.rotation.z=x>0?-.3:.3;}for(const x of [-.09,.09])box(g,x,.15,.04,.11,.22,.2,wood);}
function building(x,z,w=5,d=4,h=3.4,key=null,second=false){
  const g=new THREE.Group();g.position.set(x,.2,z);town.add(g);colliders.push({x,z,w:w/2+.2,d:d/2+.2,stall:!!key});
  box(g,0,.1,0,w+.6,.25,d+.5,stone);
  box(g,0,h/2,0,w,h,d,wall);
  for(const px of [-w/2+.14,0,w/2-.14])box(g,px,h/2,d/2+.03,.16,h,.18,wood);
  for(const py of [.4,h-1,h-.2])box(g,0,py,d/2+.07,w,.12,.16,wood);
  box(g,0,1.15,d/2+.11,.95,2.3,.16,wood);
  for(const px of [-w*.3,w*.3]){
    box(g,px,1.65,d/2+.12,1.1,1.25,.15,mat('lamp',{emissive:color('lamp'),emissiveIntensity:.35}));
    for(let k=-.4;k<=.4;k+=.2)box(g,px+k,1.65,d/2+.22,.045,1.3,.05,wood);
    box(g,px,1.65,d/2+.22,1.15,.05,.05,wood);
  }
  roofMesh(g,w+1.2,d+1.3,h);
  if(second){box(g,0,h+1.45,0,w*.72,1.4,d*.7,wall);roofMesh(g,w*.85,d*.95,h+2.2);}
  for(const px of [-w*.38,w*.38])lantern(g,px,2.65,d/2+.55);
  if(key){g.userData.shop=key;
    const board=box(g,0,h-.55,d/2+.23,w*.53,.62,.13,wood);
    const c=document.createElement('canvas');c.width=512;c.height=128;const ctx=c.getContext('2d');ctx.fillStyle=color('wood');ctx.fillRect(0,0,512,128);ctx.fillStyle=color('gold');ctx.font='64px STKaiti, serif';ctx.textAlign='center';ctx.textBaseline='middle';ctx.fillText(products[key].shop,256,64);const t=new THREE.CanvasTexture(c);const plane=new THREE.Mesh(new THREE.PlaneGeometry(w*.5,.57),new THREE.MeshBasicMaterial({map:t,toneMapped:false}));plane.position.set(0,h-.55,d/2+.31);g.add(plane);
    box(g,0,.9,d/2+1,3,.14,.7,wood);for(const px of [-1.2,1.2])box(g,px,.45,d/2+1,.1,.9,.45,wood);
    addMerchandise(g,key,d/2+1);
    addMerchant(g,w*.36,d/2+.85,key);
    colliders.push({x,z:z+d/2+1,w:1.5,d:.4,stall:true});
  }
  return g;
}
const entries=Object.entries(products);
let district=0,activeEntries=[],stalls=[];
function showDistrict(next){
 district=Math.max(0,Math.min(Math.ceil(entries.length/24)-1,next));
 for(const item of photoFaces){item.generation++;item.face.material.map?.dispose();}photoFaces.length=0;merchandise.length=0;
 for(const stall of stalls){const geometries=new Set(),materials=new Set();stall.traverse(o=>{if(o.geometry&&o.geometry!==unitBox)geometries.add(o.geometry);if(o.material&&!Array.from(materialCache.values()).includes(o.material))materials.add(o.material);});geometries.forEach(g=>g.dispose());materials.forEach(m=>{m.map?.dispose();m.dispose();});town.remove(stall);}stalls=[];
 for(let i=colliders.length-1;i>=0;i--)if(colliders[i].stall)colliders.splice(i,1);
 activeEntries=entries.slice(district*24,district*24+24);
 activeEntries.forEach(([key,p],i)=>{const x=-8+i%3*9,z=-7-Math.floor(i/3)*8;p.position=[x,1.65,z+3];stalls.push(building(x,z,5,4,3.8,key,i%2===0));});
 root.dataset.district=String(district+1);root.querySelectorAll('[data-town-district]').forEach(button=>{button.disabled=Number(button.dataset.townDistrict)<0?district===0:district>=Math.ceil(entries.length/24)-1;});
}
showDistrict(0);
root.querySelectorAll('[data-town-district]').forEach(button=>button.onclick=()=>{showDistrict(district+Number(button.dataset.townDistrict));reset();});
const shopLights=[];
for(const [,p] of activeEntries.slice(0,6)){const light=new THREE.PointLight(color('lamp'),16,9,2);light.position.set(p.position[0],2.7,p.position[2]);scene.add(light);shopLights.push(light);}
building(-17,-9,4,4,3.8,null);
building(17,-9,4,4,4.4,null,true);
building(-17,8,4,4,3.3);
building(-8,10,5,4,3.4,null,true);
building(11,10,5,4,3.5);
const portalObjects=[];
for(const [key,p] of Object.entries(portals)){const c=document.createElement('canvas');c.width=512;c.height=160;const ctx=c.getContext('2d');ctx.fillStyle=color('wood');ctx.fillRect(0,0,512,160);ctx.fillStyle=color('gold');ctx.font='56px serif';ctx.textAlign='center';ctx.textBaseline='middle';ctx.fillText(p.name,256,80);const sign=new THREE.Mesh(new THREE.PlaneGeometry(2.1,.66),new THREE.MeshBasicMaterial({map:new THREE.CanvasTexture(c),toneMapped:false}));sign.position.set(p.position[0],2.6,p.position[2]+.03);sign.userData.product=key;town.add(sign);portalObjects.push(sign);const door=box(town,p.position[0],1.35,p.position[2],1.05,2.5,.08,wood);door.userData.product=key;portalObjects.push(door);}
building(18,6,3.6,4,3.1);


// 牌楼、摊棚与树冠给小镇明确的入口与生活尺度。
const gate=new THREE.Group();gate.position.set(3,.2,11);town.add(gate);
for(const x of [-2.6,2.6])box(gate,x,2,0,.35,4,.35,wood);
box(gate,0,3.7,0,6.3,.42,.4,wood);roofMesh(gate,7,1.5,4);
box(gate,0,3.45,.3,1.5,.7,.12,gold);lantern(gate,-2.5,3,.7);lantern(gate,2.5,3,.7);
for(const x of [-17,-10,-3,8,15,20])for(const z of [-3.4,3.5]){box(town,x,1.5,z,.1,3,.1,wood);box(town,x+.3,3,z,.7,.1,.1,wood);lantern(town,x+.6,2.7,z,.25);}
function tree(x,z,scale=1,pink=false){const g=new THREE.Group();g.position.set(x,0,z);g.scale.setScalar(scale);town.add(g);cylinder(g,0,1.5,0,.16,3,wood);const m=pink?mat('silk'):green;for(let i=0;i<7;i++){const a=i*2.4,r=i%3*.5;sphere(g,Math.cos(a)*r,2.7+(i%3)*.6,Math.sin(a)*r,1.15,m);}}
tree(-14,-15,1.3);tree(16,-15,1.5);tree(-20,1,1.1);tree(20,1,1.3);tree(-13,13,1,true);tree(16,13,1,true);tree(-2,-12,.85);tree(20,-13,1.2);
for(const [x,z] of [[-4,6],[8,5],[-19,-4]]){box(town,x,.7,z,2.5,.14,1.2,wood);for(const a of [-1,1])for(const b of [-.4,.4])box(town,x+a,.35,z+b,.12,.7,.12,wood);box(town,x,2.1,z,2.9,.1,1.8,mat('silk'));for(const a of [-1.3,1.3])box(town,x+a,1.1,z,.1,2.2,.1,wood);for(let i=0;i<4;i++)sphere(town,x-.8+i*.5,.9,z,.16,gold);}
// 远山采用低面数轮廓，保持三维加载轻量。
for(let i=0;i<12;i++){const m=new THREE.Mesh(new THREE.ConeGeometry(6+i%3,12+i%4*3,5),mat('green'));m.position.set(-40+i*8,3,-38-(i%3)*6);scene.add(m);}
const moon=new THREE.Mesh(new THREE.SphereGeometry(1.2,24,16),new THREE.MeshBasicMaterial({color:color('cream')}));moon.position.set(-16,21,-22);scene.add(moon);
const fireflyGeo=new THREE.BufferGeometry();const fireflyCoords=new Float32Array(90);for(let i=0;i<90;i+=3){fireflyCoords[i]=(i*1.34%36)-18;fireflyCoords[i+1]=1+(i*1.73%4);fireflyCoords[i+2]=(i*2.71%18)-9;}fireflyGeo.setAttribute('position',new THREE.BufferAttribute(fireflyCoords,3));const flies=new THREE.Points(fireflyGeo,new THREE.PointsMaterial({color:color('lamp'),size:.065,transparent:true,opacity:.65}));town.add(flies);
function resize(){renderer.setSize(host.clientWidth,host.clientHeight,false);camera.aspect=host.clientWidth/host.clientHeight;camera.updateProjectionMatrix();}
new ResizeObserver(resize).observe(host);resize();
root.querySelector('#reset').onclick=reset;
let night=true;
root.querySelector('#time').onclick=()=>{night=!night;document.body.dataset.time=night?'night':'day';scene.background.set(color(night?'night-sky':'day-sky'));scene.fog.color.set(color(night?'fog':'day-sky'));ambient.intensity=night?1.1:3;sun.intensity=night?1.5:3.5;lampMaterial.emissiveIntensity=night?1.1:.15;shopLights.forEach(l=>l.intensity=night?16:0);flies.visible=night;moon.visible=night;};
function canWalk(x,z){
 if(Math.abs(x)>20||z>14||z<-(rows*8+10))return false;
 if(Math.abs(z)<2.75&&Math.abs(x-4)>1.3&&Math.abs(x+13)>1.3)return false;
 return !colliders.some(c=>Math.abs(x-c.x)<c.w+.22&&Math.abs(z-c.z)<c.d+.22);
}
function walk(dx,dz){const startX=camera.position.x,startZ=camera.position.z;const steps=Math.max(1,Math.ceil(Math.hypot(dx,dz)/.15));for(let i=0;i<steps;i++){const x=camera.position.x+dx/steps,z=camera.position.z+dz/steps;if(canWalk(x,camera.position.z)){camera.position.x=x;}if(canWalk(camera.position.x,z)){camera.position.z=z;}}
 const onBridge=Math.abs(camera.position.z)<=3.25&&(Math.abs(camera.position.x-4)<1.4||Math.abs(camera.position.x+13)<1.4);
 camera.position.y=1.9+(onBridge?.55+Math.sin((3.25-camera.position.z)/6.5*Math.PI)*1.05:0);return Math.hypot(camera.position.x-startX,camera.position.z-startZ)>1e-6;
}
navigateShop=key=>{
 if(portals[key]){const p=portals[key];routeKey=key;route=[];if(camera.position.z<-2.75){if(camera.position.z<-4)route.push([20,camera.position.z],[20,-3.05]);route.push([4,-3.05],[4,3.2]);}else if(camera.position.z<2.75)route.push([camera.position.x,3.2]);else if(camera.position.z>4)route.push([camera.position.x,3.2]);route.push([-20,3.2],[-20,13.4],[p.position[0],13.4],[p.position[0],p.position[2]+1.25]);notice(root.dataset.travelLabel+' · '+p.name);return;}
 const next=Math.floor(entries.findIndex(([id])=>id===key)/24);if(next!==district){showDistrict(next);reset();}const p=products[key],z=p.position[2]+1;
 routeKey=key;route=[];
 // 先沿青石街到桥头，过桥后再沿北岸走向摊位。
 if(camera.position.z>2.75){route.push([camera.position.x,3.2],[4,3.2],[4,-3.05]);}
 else if(camera.position.z>-2.75){route.push([camera.position.x,-3.05]);}
 else if(camera.position.z<-4){route.push([20,camera.position.z],[20,-3.05]);}
 route.push([20,-3.05],[20,z],[p.position[0]-1.6,z]);root.querySelector('#arrival').hidden=true;notice(root.dataset.travelLabel+' · '+p.name);
};
function updateNearby(){
 let closest=null,best=2.5*2.5;
 for(const [key,p] of [...activeEntries,...Object.entries(portals)]){const dx=camera.position.x-p.position[0],dz=camera.position.z-p.position[2],distance=dx*dx+dz*dz;if(distance<best){best=distance;closest=key;}}
 nearby=closest;
 const interaction=root.querySelector('#interaction');interaction.hidden=!nearby||route.length>0;
 if(nearby){root.querySelector('#nearby-name').textContent=destinations[nearby].name;root.querySelector('#inspect').textContent=destinations[nearby].kind==='portal'?root.dataset.enterLabel:root.dataset.inspectLabel;}
 const loc=root.querySelector('#location');const text=route.length?root.dataset.travelLabel+' · '+destinations[routeKey].name:nearby?destinations[nearby].name:root.dataset.marketLabel;
 if(loc.textContent!==text)loc.textContent=text;loc.dataset.position=camera.position.x.toFixed(2)+','+camera.position.z.toFixed(2);
}
inspectNearby=()=>{if(nearby)openShop(nearby);};
openService=()=>{const closest=Object.entries(portals).reduce((best,entry)=>Math.hypot(camera.position.x-entry[1].position[0],camera.position.z-entry[1].position[2])<Math.hypot(camera.position.x-best[1].position[0],camera.position.z-best[1].position[2])?entry:best);openShop(closest[0]);};
window.addEventListener('keydown',e=>{if(e.target.closest?.('input,textarea,select,[contenteditable]')||!root.contains(document.activeElement))return;const key=e.key.toLowerCase();if(['w','a','s','d','arrowup','arrowdown','arrowleft','arrowright'].includes(key)){e.preventDefault();keys.add(key);route=[];}if(key==='e')inspectNearby();if(key==='escape')keys.clear();});
window.addEventListener('keyup',e=>keys.delete(e.key.toLowerCase()));window.addEventListener('blur',()=>keys.clear());
root.querySelectorAll('[data-move]').forEach(button=>{
 const map={forward:'w',left:'a',back:'s',right:'d'};const key=map[button.dataset.move];
 button.addEventListener('pointerdown',e=>{e.preventDefault();button.setPointerCapture(e.pointerId);keys.add(key);route=[];});
 for(const event of ['pointerup','pointercancel','lostpointercapture'])button.addEventListener(event,()=>keys.delete(key));
});
let drag=null,movedPointer=false;
renderer.domElement.addEventListener('pointerdown',e=>{renderer.domElement.focus({preventScroll:true});drag={x:e.clientX,y:e.clientY};movedPointer=false;renderer.domElement.setPointerCapture(e.pointerId);});
renderer.domElement.addEventListener('pointermove',e=>{if(!drag||document.querySelector('dialog[open]'))return;const dx=e.clientX-drag.x,dy=e.clientY-drag.y;if(Math.abs(dx)+Math.abs(dy)>2)movedPointer=true;yaw-=dx*.004;pitch=THREE.MathUtils.clamp(pitch-dy*.004,-1.05,.75);drag={x:e.clientX,y:e.clientY};});
const ray=new THREE.Raycaster(),mouse=new THREE.Vector2();
renderer.domElement.addEventListener('pointerup',e=>{drag=null;if(movedPointer||document.querySelector('dialog[open]'))return;const r=renderer.domElement.getBoundingClientRect();mouse.set((e.clientX-r.left)/r.width*2-1,-(e.clientY-r.top)/r.height*2+1);ray.setFromCamera(mouse,camera);const hit=ray.intersectObjects([...merchandise,...portalObjects],true)[0];if(hit){let o=hit.object;while(o&&!o.userData.product)o=o.parent;if(o){const p=destinations[o.userData.product];if(Math.hypot(camera.position.x-p.position[0],camera.position.z-(p.position[2]))<3.5)openShop(o.userData.product);else navigateShop(o.userData.product);}}});
renderer.domElement.addEventListener('pointercancel',()=>drag=null);
let last=0,visible=true;
const visibilityObserver=new IntersectionObserver(records=>{visible=records[0]?.isIntersecting??true;if(!visible)keys.clear();},{threshold:.01});visibilityObserver.observe(host);
function frame(t){requestAnimationFrame(frame);if(document.hidden||!visible){last=t;return;}if(t-last<25)return;const dt=Math.min((t-last)/1000,.5);last=t;
 if(!document.querySelector('dialog[open]')){
 if(route.length){const [x,z]=route[0],dx=x-camera.position.x,dz=z-camera.position.z,d=Math.hypot(dx,dz);if(d<.12){route.shift();if(!route.length){const p=destinations[routeKey];aim(p.position[0],p.position[1],p.position[2]);}}
 else{const step=Math.min(d,dt*6.5);yaw=Math.atan2(-dx,-dz);pitch=-.03;if(!walk(dx/d*step,dz/d*step)){route=[];notice(root.dataset.marketLabel);}}}
 else{let forward=(keys.has('w')||keys.has('arrowup')?1:0)-(keys.has('s')||keys.has('arrowdown')?1:0);let side=(keys.has('d')||keys.has('arrowright')?1:0)-(keys.has('a')||keys.has('arrowleft')?1:0);if(forward||side){const n=Math.hypot(forward,side);walk((-Math.sin(yaw)*forward+Math.cos(yaw)*side)/n*dt*4.5,(-Math.cos(yaw)*forward-Math.sin(yaw)*side)/n*dt*4.5);root.querySelector('#arrival').hidden=true;}}
 }
 camera.rotation.order='YXZ';camera.rotation.set(pitch,yaw,0);updateNearby();
 for(const item of photoFaces){const distance=Math.hypot(camera.position.x-item.p.position[0],camera.position.z-item.p.position[2]);if(distance<12&&item.state==='idle'){item.state='loading';const generation=++item.generation;new THREE.TextureLoader().load(item.p.image,texture=>{if(generation!==item.generation){texture.dispose();return;}texture.colorSpace=THREE.SRGBColorSpace;item.face.material.color.set('white');item.face.material.map=texture;item.face.material.needsUpdate=true;item.state='ready';},undefined,()=>{item.state='failed';});}else if(distance>18&&item.state==='ready'){item.face.material.map.dispose();item.face.material.map=null;item.face.material.color.set(color('wood'));item.face.material.needsUpdate=true;item.state='idle';}}
 if(!reduced){flies.rotation.y=Math.sin(t*.00012)*.04;}
 renderer.render(scene,camera);
}
requestAnimationFrame(frame);root.dataset.scene='ready';document.body.classList.add('daocharms-3d-ready');resize();
}

}
