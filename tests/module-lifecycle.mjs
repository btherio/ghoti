import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
const source=path=>readFileSync(new URL('../'+path,import.meta.url),'utf8');
class CustomEvent {
    constructor(type,options={}){this.type=type;Object.assign(this,options);this.defaultPrevented=false;}
    preventDefault(){if(this.cancelable)this.defaultPrevented=true;}
}
function loginFixture(consume=false){
    const calls={sessions:0,pages:0,logout:0,events:[]};
    const context=vm.createContext({document:{dispatchEvent(event){calls.events.push(event);if(consume && ['ghoti:authenticated','ghoti:before-logout'].includes(event.type))event.preventDefault();return !event.defaultPrevented;}},window:{setTimeout(){}},$:()=>({ready(){},html(){}}),CustomEvent,x_printSystemMenu(){},x_refreshPrivateMenu(){},x_isAdmin(){},x_setSessionVars(id,callback){calls.sessions++;callback();},printPage(){},x_getDefaultPage(){calls.pages++;},x_logout(){calls.logout++;},cancelPopup(){}});
    vm.runInContext(source('mod/login/login.js'),context);
    return {context,calls};
}
test('existing-session checks publish authentication without regenerating a session or navigating',()=>{
    const {context,calls}=loginFixture();context.checkLogin_cb(42);
    assert.equal(calls.sessions,0);assert.equal(calls.pages,0);assert.equal(calls.events[0].detail.existing,true);
});
test('new sign-in keeps the normal Home fallback unless a module consumes navigation',()=>{
    const ordinary=loginFixture();ordinary.context.loginEstablished(42);
    assert.equal(ordinary.calls.sessions,1);assert.equal(ordinary.calls.pages,1);
    const mounted=loginFixture(true);mounted.context.loginEstablished(42);
    assert.equal(mounted.calls.sessions,1);assert.equal(mounted.calls.pages,0);
});
test('a draft owner can cancel logout before session revocation',()=>{
    const {context,calls}=loginFixture(true);context.logout();assert.equal(calls.logout,0);
    const ordinary=loginFixture();ordinary.context.logout();assert.equal(ordinary.calls.logout,1);
});
test('page rendering announces unmount and mount around content replacement',()=>{
    const calls=[];
    const chain={html(value){calls.push(value);return this;},addClass(){return this;},find(){return this;},removeClass(){return this;},slideUp(){return this;}};
    const context=vm.createContext({document:{dispatchEvent:event=>calls.push(event.type)},$:()=>chain,ghotiPageEditorState:{dirty:false},setTimeout(){},CustomEvent});
    const js=source('ghoti.js');vm.runInContext(js.slice(js.indexOf('function printPage(content)'),js.indexOf('function popup_cb')),context);
    context.printPage('<p>new page</p>');
    assert.deepEqual(calls,['ghoti:before-page-render','<p>new page</p>','ghoti:page-rendered']);
});
test('manifest module checkboxes are included in the native settings payload',()=>{
    let payload;
    const boxes=[{dataset:{ghotiExtension:'example'},checked:true},{dataset:{ghotiExtension:'second'},checked:false}];
    const context=vm.createContext({document:{querySelectorAll:()=>boxes},$:()=>({val:()=>'',is:()=>false}),saveSiteSettingsAfterMail:value=>payload=value});
    const js=source('ghoti.js');vm.runInContext(js.slice(js.indexOf('function saveSiteSettings(){'),js.indexOf('/* The Mail tab',js.indexOf('function saveSiteSettings(){'))),context);
    context.saveSiteSettings();assert.equal(payload.extensionModules.example,true);assert.equal(payload.extensionModules.second,false);
});
