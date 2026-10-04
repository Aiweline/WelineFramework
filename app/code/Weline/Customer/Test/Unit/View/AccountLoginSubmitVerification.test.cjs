const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('fs');const vm=require('vm');const path=require('path');
async function attempt(prevented) {
 class Element {constructor(){this.dataset={};this.classList={toggle(){},add(){},remove(){}};}setAttribute(){} querySelector(){return null;}querySelectorAll(){return [];} scrollIntoView(){} }
 class Input extends Element {constructor(value){super();this.value=value;}}
 class Button extends Element {}
 class Form extends Element {getAttribute(){return '/customer/account/login/post';}}
 const form=new Form(),username=new Input('local@example.test'),password=new Input('local-test-password');
 const button=new Button(),feedback=new Element();const section=new Element();
 section.querySelector=s=>({'[data-w-login-form]':form,'[data-w-login-submit]':button,'[data-w-login-feedback]':feedback,'#username':username,'#password':password}[s]||null);
 const listeners=new Map();let calls=0;
 const context={HTMLElement:Element,HTMLInputElement:Input,HTMLButtonElement:Button,HTMLFormElement:Form,Element,
 window:{setTimeout(){},location:{pathname:'/customer/account/login',origin:'https://local.test',assign(){}},Weline:{}},
 document:{getElementById(){return null;}},URL,URLSearchParams,
 FormData:class {get(){return null;}set(){}},matchMedia:()=>({matches:true}),setTimeout(){},
 fetch:async()=>{calls++;return {ok:true,json:async()=>({success:true,status:'authenticated',redirect:'/customer/account'})};}};
 vm.createContext(context);
 vm.runInContext(fs.readFileSync(path.resolve(__dirname,'../../../view/statics/js/account-login.js'),'utf8').replace('export function register','function register'),context);
 context.register({define(name,fn){if(name==='account-login')fn({element:section,listen(target,event,fn){if(target===form)listeners.set(event,fn);}});}});
 await listeners.get('submit')({defaultPrevented:prevented,preventDefault(){this.defaultPrevented=true;}});
 return calls;
}
test('verification-deferred submit cannot send an empty-token login',async()=>assert.equal(await attempt(true),0));
test('verified submit still sends one native document login',async()=>assert.equal(await attempt(false),1));
