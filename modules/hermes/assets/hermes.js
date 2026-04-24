document.addEventListener("DOMContentLoaded",function(){

const ajax = window.metisHermesAjax || null

const ORB_SIZE = 50

const HERMES_AVATAR = ajax && ajax.avatar_url ? String(ajax.avatar_url) : "hermes.png"
let USER_AVATAR = ajax && ajax.user_avatar_url ? String(ajax.user_avatar_url) : "user.png"

const container = document.getElementById("hermes-container")
const canvas = document.getElementById("hermesCanvas")
const panel = document.getElementById("hermes-panel")

const sendBtn = document.getElementById("sendBtn")
const input = document.getElementById("chatInput")
const messages = document.getElementById("messages")

const testPulse = document.getElementById("testPulse")
const testThink = document.getElementById("testThink")

const closeBtn = document.getElementById("closeHermes")

const headerAvatar = document.getElementById("hermesHeaderAvatar")

if(!container || !canvas || !panel || !sendBtn || !input || !messages || !closeBtn || !headerAvatar){
return
}

headerAvatar.style.backgroundImage=`url(${HERMES_AVATAR})`

const ctx = canvas.getContext("2d")

const ratio = window.devicePixelRatio || 1

container.style.width = ORB_SIZE+"px"
container.style.height = ORB_SIZE+"px"

canvas.width = ORB_SIZE*ratio
canvas.height = ORB_SIZE*ratio

canvas.style.width = ORB_SIZE+"px"
canvas.style.height = ORB_SIZE+"px"

ctx.scale(ratio,ratio)

const center = ORB_SIZE/2

function positionPanel(){

const rect = container.getBoundingClientRect()

panel.style.right = window.innerWidth-rect.right+"px"
panel.style.bottom = rect.height+20+"px"

}

window.addEventListener("resize",positionPanel)

let hue = 210
let pulse = 0
let thinking=false
let pendingRequests=0

let collapsing=false
let collapseRadius=ORB_SIZE

let open=false
let sessionCode=""
let bootstrapLoaded=false

function escapeHtml(value){
return String(value==null?"":value)
.replace(/&/g,"&amp;")
.replace(/</g,"&lt;")
.replace(/>/g,"&gt;")
.replace(/"/g,"&quot;")
.replace(/'/g,"&#39;")
}

container.onclick=function(){

if(open) return

panel.hidden = false
panel.classList.add("open")

open=true

pulse=10

positionPanel()
bootstrapHistory()

}

container.addEventListener("keydown",e=>{
if(e.key==="Enter" || e.key===" "){
e.preventDefault()
container.click()
}
})

closeBtn.onclick=function(){

panel.classList.remove("open")

open=false

collapseRadius=ORB_SIZE
collapsing=true

setTimeout(()=>{
if(!open){
panel.hidden = true
}
},250)

}

function addMessage(text,type){

const row=document.createElement("div")
row.className="msg-row "+type

const avatar=document.createElement("div")
avatar.className="msg-avatar"

avatar.style.backgroundImage=`url(${type==="hermes"?HERMES_AVATAR:USER_AVATAR})`

const bubble=document.createElement("div")
bubble.className="msg "+type
bubble.textContent=text

row.appendChild(avatar)
row.appendChild(bubble)

messages.appendChild(row)

messages.scrollTop=messages.scrollHeight

}

function clearMessages(){
messages.innerHTML=""
}

function hydrateHistory(history){
clearMessages()

if(!Array.isArray(history) || history.length < 1){
addMessage("Submit an operational request.","hermes")
return
}

history.forEach(item=>{
const role=String(item && item.role_name ? item.role_name : "hermes")
const content=String(item && item.content ? item.content : "")
if(!content) return
addMessage(content,role==="user" ? "user" : "hermes")
})
}

function addRichMessage(html,type){

const row=document.createElement("div")
row.className="msg-row "+type

const avatar=document.createElement("div")
avatar.className="msg-avatar"

avatar.style.backgroundImage=`url(${type==="hermes"?HERMES_AVATAR:USER_AVATAR})`

const bubble=document.createElement("div")
bubble.className="msg "+type
bubble.innerHTML=html

row.appendChild(avatar)
row.appendChild(bubble)

messages.appendChild(row)

messages.scrollTop=messages.scrollHeight

}

function actionCard(action){
const preview=action && action.preview ? action.preview : {}
const actionCode=action && action.action_code ? String(action.action_code) : ""
const title=action && action.title ? String(action.title) : (preview.title || "Hermes Action")
const summary=preview && preview.summary ? String(preview.summary) : "Approval required."

return `<div class="hermes-action-card" data-action-code="${escapeHtml(actionCode)}">
<div class="hermes-action-title">${escapeHtml(title)}</div>
<div class="hermes-action-summary">${escapeHtml(summary)}</div>
<div class="hermes-action-buttons">
<button type="button" class="hermes-inline-btn" data-hermes-preview="${escapeHtml(actionCode)}">Preview</button>
<button type="button" class="hermes-inline-btn primary" data-hermes-approve="${escapeHtml(actionCode)}">Approve</button>
</div>
</div>`
}

function renderConversationResponse(data){
const answer=data && data.reasoning && data.reasoning.answer ? String(data.reasoning.answer) : "Operation request received."
const structured=(data && data.reasoning && data.reasoning.structured && typeof data.reasoning.structured==="object"
? data.reasoning.structured
: (data && typeof data==="object" ? data : null))
let html=`<div class="hermes-answer">${escapeHtml(answer)}</div>`

if(data && data.reasoning && data.reasoning.grounding && Array.isArray(data.reasoning.grounding.grounded) && data.reasoning.grounding.grounded.length){
html+=`<div class="hermes-grounding">Grounded in ${escapeHtml(data.reasoning.grounding.grounded.slice(0,3).map(g=>g.label).join(", "))}</div>`
}

if(structured && structured.result){
html+=renderOperationResult(structured.result)
}else if(data && data.result){
html+=renderOperationResult(data.result)
}

if(data && Array.isArray(data.actions) && data.actions.length){
html+=`<div class="hermes-action-list">`
data.actions.forEach(action=>{
html+=actionCard(action)
})
html+=`</div>`
}

addRichMessage(html,"hermes")
}

function renderDiagnosticResponse(data){
const count=data && data.diagnostics && data.diagnostics.summary ? Number(data.diagnostics.summary.finding_count||0) : 0
let html=`<div class="hermes-answer">Diagnostics completed. ${count} findings returned.</div>`

if(data && data.report && data.report.report_code){
html+=`<div class="hermes-grounding">Report ${escapeHtml(String(data.report.report_code))} saved.</div>`
}

if(data && data.diagnostics && Array.isArray(data.diagnostics.findings)){
html+=`<div class="hermes-findings">`
data.diagnostics.findings.slice(0,3).forEach(finding=>{
html+=`<div class="hermes-finding"><strong>${escapeHtml(String(finding.title||"Finding"))}</strong><span>${escapeHtml(String(finding.severity||"info"))}</span></div>`
})
html+=`</div>`
}

addRichMessage(html,"hermes")
}

function previewAction(actionCode){
request("metis_hermes_preview_action",{action_code:actionCode}).then(data=>{
const preview=data && data.preview ? data.preview : {}
let html=`<div class="hermes-answer">${escapeHtml(String(preview.title||"Action Preview"))}</div>`
if(preview.summary){
html+=`<div class="hermes-grounding">${escapeHtml(String(preview.summary))}</div>`
}
if(preview.approval_copy){
html+=`<div class="hermes-rich-copy">${escapeHtml(String(preview.approval_copy))}</div>`
}
if(Array.isArray(preview.effects) && preview.effects.length){
html+=`<div class="hermes-findings">`
preview.effects.forEach(effect=>{
html+=`<div class="hermes-finding"><strong>Effect</strong><span>${escapeHtml(String(effect))}</span></div>`
})
html+=`</div>`
}
html+=`<div class="hermes-action-buttons">
<button type="button" class="hermes-inline-btn primary" data-hermes-approve="${escapeHtml(actionCode)}">Approve</button>
</div>`
addRichMessage(html,"hermes")
}).catch(error=>{
addMessage(error && error.message ? String(error.message) : "Preview failed.","hermes")
})
}

function approveAction(actionCode){
const approveBtns=messages.querySelectorAll(`[data-hermes-approve="${CSS.escape(actionCode)}"]`)
approveBtns.forEach(btn=>{
btn.disabled=true
btn.textContent="Approved"
btn.setAttribute("aria-disabled","true")
})
const previewBtns=messages.querySelectorAll(`[data-hermes-preview="${CSS.escape(actionCode)}"]`)
previewBtns.forEach(btn=>{
btn.disabled=true
btn.setAttribute("aria-disabled","true")
})

request("metis_hermes_approve_action",{action_code:actionCode}).then(()=>{
addRichMessage(`<div class="hermes-answer">Approval recorded. Executing now...</div>`,"hermes")
executeAction(actionCode)
}).catch(error=>{
approveBtns.forEach(btn=>{
btn.disabled=false
btn.textContent="Approve"
btn.removeAttribute("aria-disabled")
})
previewBtns.forEach(btn=>{
btn.disabled=false
btn.removeAttribute("aria-disabled")
})
addMessage(error && error.message ? String(error.message) : "Approve failed.","hermes")
})
}

function executeAction(actionCode){
const executeBtn=messages.querySelector(`[data-hermes-execute="${CSS.escape(actionCode)}"]`)
if(executeBtn){
executeBtn.disabled=true
executeBtn.textContent="Executing..."
}
request("metis_hermes_execute_action",{action_code:actionCode}).then(data=>{
let html=`<div class="hermes-answer">Approved operation executed.</div>`
const result=data && data.result ? data.result : {}

if(result.mission){
html+=renderMissionResult(result.mission,result.report || null)
}else if(result.help_topic){
html+=renderHelpTopicResult(result.help_topic)
}else if(result.walkthrough){
html+=renderWalkthroughResult(result.walkthrough,result.launched)
}else if(result.queued){
html+=renderQueuedResult(result.queued)
}else if(result.diagnostics){
html+=renderDiagnosticExecutionResult(result.diagnostics,result.report || null)
}else if(result && Array.isArray(result.context_packs)){
if(result.message){
html+=`<div class="hermes-grounding">${escapeHtml(String(result.message))}</div>`
}
if(result.result){
html+=renderOperationResult(result.result)
}
}else{
const rendered=renderOperationResult(result)
if(rendered){
html+=rendered
}
}

addRichMessage(html,"hermes")
}).catch(error=>{
if(executeBtn){
executeBtn.disabled=false
executeBtn.textContent="Execute"
}
addMessage(error && error.message ? String(error.message) : "Execute failed.","hermes")
})
}

function renderOperationResult(result){
if(!result || typeof result!=="object"){
return ""
}

if(result.status==="error"){
let html=`<div class="hermes-grounding">${escapeHtml(String(result.message||"Sorry, I had trouble getting that for you."))}</div>`
if(result.detail){
html+=`<div class="hermes-rich-copy">${escapeHtml(String(result.detail))}</div>`
}
return html
}

if(result.ok && result.run_uuid){
return ``
}

if(result.status==="success" && result.diagnosis){
let html=`<div class="hermes-findings">`
html+=`<div class="hermes-finding"><strong>User</strong><span>${escapeHtml(String(result.user||"Unknown"))}</span></div>`
if(typeof result.permission_count!=="undefined"){
html+=`<div class="hermes-finding"><strong>Permission Count</strong><span>${escapeHtml(String(result.permission_count||0))}</span></div>`
}
if(result.missing_permission){
html+=`<div class="hermes-finding"><strong>Missing Permission</strong><span>${escapeHtml(String(result.missing_permission))}</span></div>`
}
if(result.suggested_fix){
html+=`<div class="hermes-finding"><strong>Suggested Fix</strong><span>${escapeHtml(String(result.suggested_fix))}</span></div>`
}
html+=`</div>`
if(Array.isArray(result.permission_summary) && result.permission_summary.length){
html+=`<div class="hermes-grounding">Permission summary</div>`
html+=`<div class="hermes-findings">`
result.permission_summary.forEach(group=>{
const moduleLabel=String(group.module_label||group.module||"General")
const actions=Array.isArray(group.actions) ? group.actions.join(", ") : ""
const permissions=Array.isArray(group.permissions) ? group.permissions.join(", ") : ""
html+=`<div class="hermes-finding"><strong>${escapeHtml(moduleLabel)}</strong><span>${escapeHtml(actions || permissions || "Access granted")}</span></div>`
})
html+=`</div>`
}else if(!result.issue_found){
html+=`<div class="hermes-grounding">No effective permissions were resolved for this person.</div>`
}
return html
}

if(result.status==="success" && result.profile){
const profile=result.profile || {}
const person=profile.person || {}
const contact=profile.contact || {}
const donor=profile.donor || {}
let html=`<div class="hermes-findings">`
html+=`<div class="hermes-finding"><strong>Name</strong><span>${escapeHtml(String(profile.name||"Unknown"))}</span></div>`
if(profile.entity_type){
html+=`<div class="hermes-finding"><strong>Record Type</strong><span>${escapeHtml(String(profile.entity_type))}</span></div>`
}
if(person.email || contact.email){
html+=`<div class="hermes-finding"><strong>Email</strong><span>${escapeHtml(String(person.email||contact.email||""))}</span></div>`
}
if(Array.isArray(contact.emails) && contact.emails.length){
html+=`<div class="hermes-finding"><strong>Emails</strong><span>${escapeHtml(contact.emails.join(", "))}</span></div>`
}
if(Array.isArray(contact.newsletter_lists) && contact.newsletter_lists.length){
html+=`<div class="hermes-finding"><strong>Newsletters</strong><span>${escapeHtml(contact.newsletter_lists.join(", "))}</span></div>`
}
if(contact.phone){
html+=`<div class="hermes-finding"><strong>Phone</strong><span>${escapeHtml(String(contact.phone))}</span></div>`
}
if(contact.address){
html+=`<div class="hermes-finding"><strong>Address</strong><span>${escapeHtml(String(contact.address))}</span></div>`
}
if(person.workspace_email){
html+=`<div class="hermes-finding"><strong>Workspace Email</strong><span>${escapeHtml(String(person.workspace_email))}</span></div>`
}
if(person.status){
html+=`<div class="hermes-finding"><strong>Status</strong><span>${escapeHtml(String(person.status))}</span></div>`
}
html+=`</div>`
if(donor && donor.show_summary){
html+=`<div class="hermes-grounding">Giving summary</div>`
html+=`<div class="hermes-findings">`
html+=`<div class="hermes-finding"><strong>This Year</strong><span>${escapeHtml(formatCurrency(donor.this_year_total||0))}</span></div>`
html+=`<div class="hermes-finding"><strong>Last Year</strong><span>${escapeHtml(formatCurrency(donor.last_year_total||0))}</span></div>`
html+=`<div class="hermes-finding"><strong>Lifetime</strong><span>${escapeHtml(formatCurrency(donor.lifetime_total||0))}</span></div>`
if(typeof donor.gift_count!=="undefined"){
html+=`<div class="hermes-finding"><strong>Gift Count</strong><span>${escapeHtml(String(donor.gift_count||0))}</span></div>`
}
if(donor.last_gift_at){
html+=`<div class="hermes-finding"><strong>Last Gift</strong><span>${escapeHtml(String(donor.last_gift_at))}</span></div>`
}
html+=`</div>`
}
return html
}

if(result.status==="success" && result.giving_summary){
const summary=result.giving_summary || {}
let html=`<div class="hermes-findings">`
html+=`<div class="hermes-finding"><strong>Total Raised</strong><span>${escapeHtml(formatCurrency(summary.total_raised||0))}</span></div>`
if(typeof summary.gift_count!=="undefined"){
html+=`<div class="hermes-finding"><strong>Gift Count</strong><span>${escapeHtml(String(summary.gift_count||0))}</span></div>`
}
if(typeof summary.donor_count!=="undefined"){
html+=`<div class="hermes-finding"><strong>Donor Count</strong><span>${escapeHtml(String(summary.donor_count||0))}</span></div>`
}
if(summary.last_gift_at){
html+=`<div class="hermes-finding"><strong>Last Gift</strong><span>${escapeHtml(String(summary.last_gift_at))}</span></div>`
}
html+=`</div>`
return html
}

if(result.status==="success" && Array.isArray(result.options) && result.options.length){
let html=`<div class="hermes-findings">`
result.options.forEach(option=>{
html+=`<div class="hermes-finding"><strong>Option</strong><span>${escapeHtml(String(option))}</span></div>`
})
html+=`</div>`
if(result.next_step){
html+=`<div class="hermes-grounding">${escapeHtml(String(result.next_step))}</div>`
}
return html
}

if(result.status==="success" && Array.isArray(result.actors)){
let html=``
if(result.permission_key){
html+=`<div class="hermes-grounding">Capability ${escapeHtml(String(result.permission_key))}</div>`
}
if(typeof result.actor_count!=="undefined"){
html+=`<div class="hermes-findings"><div class="hermes-finding"><strong>Matching People</strong><span>${escapeHtml(String(result.actor_count||0))}</span></div></div>`
}
if(result.actors.length){
html+=`<div class="hermes-findings">`
result.actors.forEach(actor=>{
const roleText=Array.isArray(actor.roles) && actor.roles.length ? actor.roles.join(", ") : "No roles recorded"
html+=`<div class="hermes-finding"><strong>${escapeHtml(String(actor.name||actor.email||"Person"))}</strong><span>${escapeHtml(roleText)}</span></div>`
})
html+=`</div>`
}
return html
}

if(result.status==="success" && result.user){
const user=result.user || {}
let html=`<div class="hermes-findings">`
html+=`<div class="hermes-finding"><strong>User</strong><span>${escapeHtml(String(user.name||user.email||user.pid||"User"))}</span></div>`
if(user.email){
html+=`<div class="hermes-finding"><strong>Email</strong><span>${escapeHtml(String(user.email))}</span></div>`
}
if(user.workspace_email){
html+=`<div class="hermes-finding"><strong>Workspace Email</strong><span>${escapeHtml(String(user.workspace_email))}</span></div>`
}
if(Array.isArray(user.roles) && user.roles.length){
html+=`<div class="hermes-finding"><strong>Roles</strong><span>${escapeHtml(user.roles.join(", "))}</span></div>`
}
html+=`</div>`
if(Array.isArray(result.groups) && result.groups.length){
html+=`<div class="hermes-grounding">Workspace groups</div>`
html+=`<div class="hermes-findings">`
result.groups.forEach(group=>{
html+=`<div class="hermes-finding"><strong>Group</strong><span>${escapeHtml(String(group||""))}</span></div>`
})
html+=`</div>`
}
if(result.folder){
html+=`<div class="hermes-findings">`
html+=`<div class="hermes-finding"><strong>Drive Folder</strong><span>${escapeHtml(String(result.folder.folder_name||result.folder.folder_id||"Folder linked"))}</span></div>`
html+=`</div>`
}
if(result.credential_package && result.credential_package.password){
html+=`<div class="hermes-grounding">Credential package</div>`
html+=`<div class="hermes-findings">`
html+=`<div class="hermes-finding"><strong>Password</strong><span>${escapeHtml(String(result.credential_package.password))}</span></div>`
if(result.credential_package.delivery_note){
html+=`<div class="hermes-finding"><strong>Delivery</strong><span>${escapeHtml(String(result.credential_package.delivery_note))}</span></div>`
}
html+=`</div>`
}

if(Array.isArray(result.secret_reveals) && result.secret_reveals.length){
html+=`<div class="hermes-grounding">Sensitive credentials</div>`
html+=`<div class="hermes-action-list">`
result.secret_reveals.forEach(item=>{
const token=String(item && item.token ? item.token : "")
const label=String(item && item.label ? item.label : "Temporary password")
if(!token){
return
}
html+=`<div class="hermes-action-card">
<div class="hermes-action-title">${escapeHtml(label)}</div>
<div class="hermes-action-summary">Hidden in transcript. Reveal once within 10 minutes.</div>
<div class="hermes-action-buttons">
<button type="button" class="hermes-inline-btn primary" data-hermes-reveal="${escapeHtml(token)}">Reveal once</button>
</div>
</div>`
})
html+=`</div>`
}
return html
}

return ``
}

function formatCurrency(value){
const amount=Number(value||0)
if(!Number.isFinite(amount)){
return "$0.00"
}

return amount.toLocaleString(undefined,{style:"currency",currency:"USD"})
}

function renderHelpTopicResult(topic){
let html=`<div class="hermes-grounding">${escapeHtml(String(topic.title||"Help topic"))}</div>`
if(topic.description){
html+=`<div class="hermes-rich-copy">${escapeHtml(String(topic.description))}</div>`
}
if(topic.learn_more){
html+=`<div class="hermes-link-row"><a class="hermes-inline-link" href="${escapeHtml(String(topic.learn_more))}">Open documentation</a></div>`
}
return html
}

function renderWalkthroughResult(walkthrough,launched){
let html=`<div class="hermes-grounding">${escapeHtml(String(walkthrough.title||walkthrough.id||"Walkthrough"))}</div>`
if(walkthrough.description){
html+=`<div class="hermes-rich-copy">${escapeHtml(String(walkthrough.description))}</div>`
}
html+=`<div class="hermes-findings"><div class="hermes-finding"><strong>Status</strong><span>${launched ? "Launched" : "Ready"}</span></div>`
if(walkthrough.module){
html+=`<div class="hermes-finding"><strong>Module</strong><span>${escapeHtml(String(walkthrough.module))}</span></div>`
}
html+=`</div>`
return html
}

function renderMissionResult(mission,report){
let html=`<div class="hermes-grounding">${escapeHtml(String(mission.title||mission.key||"Mission"))}</div>`
if(mission.objective){
html+=`<div class="hermes-rich-copy">${escapeHtml(String(mission.objective))}</div>`
}
if(Array.isArray(mission.phases) && mission.phases.length){
html+=`<div class="hermes-findings">`
mission.phases.forEach(phase=>{
const outputs=Array.isArray(phase.outputs) ? phase.outputs.join(", ") : ""
html+=`<div class="hermes-finding"><strong>${escapeHtml(String(phase.key||"phase"))}</strong><span>${escapeHtml(outputs || "ready")}</span></div>`
})
html+=`</div>`
}
if(report && report.report_code){
html+=`<div class="hermes-grounding">Report ${escapeHtml(String(report.report_code))} saved.</div>`
}
return html
}

function renderQueuedResult(queued){
let html=`<div class="hermes-grounding">Scheduled diagnostics queued.</div>`
html+=`<div class="hermes-findings">`
if(typeof queued.job_code!=="undefined"){
html+=`<div class="hermes-finding"><strong>Job</strong><span>${escapeHtml(String(queued.job_code||"pending"))}</span></div>`
}
if(typeof queued.status!=="undefined"){
html+=`<div class="hermes-finding"><strong>Status</strong><span>${escapeHtml(String(queued.status||"queued"))}</span></div>`
}
if(typeof queued.duplicate!=="undefined"){
html+=`<div class="hermes-finding"><strong>Duplicate</strong><span>${queued.duplicate ? "Yes" : "No"}</span></div>`
}
html+=`</div>`
return html
}

function renderDiagnosticExecutionResult(diagnostics,report){
const count=diagnostics && diagnostics.summary ? Number(diagnostics.summary.finding_count||0) : 0
let html=`<div class="hermes-grounding">Diagnostic execution returned ${count} findings.</div>`
if(report && report.report_code){
html+=`<div class="hermes-grounding">Report ${escapeHtml(String(report.report_code))} saved.</div>`
}
if(diagnostics && Array.isArray(diagnostics.findings)){
html+=`<div class="hermes-findings">`
diagnostics.findings.slice(0,4).forEach(finding=>{
html+=`<div class="hermes-finding"><strong>${escapeHtml(String(finding.title||"Finding"))}</strong><span>${escapeHtml(String(finding.severity||"info"))}</span></div>`
})
html+=`</div>`
}
return html
}

function request(action,data){
pendingRequests+=1
thinking=true
pulse=Math.max(pulse,14)

return Metis.request.post(ajax,action,data||{},"Hermes AJAX config missing.")
.finally(()=>{
pendingRequests=Math.max(0,pendingRequests-1)
thinking=pendingRequests>0
if(!thinking){
pulse=Math.max(pulse,8)
}
})
}

function revealSecret(token){
if(!token){
return
}

request("metis_hermes_reveal_secret",{reveal_token:token}).then(data=>{
const label=String(data && data.label ? data.label : "Temporary password")
const secret=String(data && data.secret ? data.secret : "")
let html=`<div class="hermes-grounding">${escapeHtml(label)}</div>`
if(secret){
html+=`<div class="hermes-rich-copy">${escapeHtml(secret)}</div>`
}
if(data && data.message){
html+=`<div class="hermes-grounding">${escapeHtml(String(data.message))}</div>`
}
addRichMessage(html,"hermes")
}).catch(error=>{
addMessage(error && error.message ? String(error.message) : "Secret reveal failed.","hermes")
})
}

function bootstrapHistory(){
if(bootstrapLoaded){
return Promise.resolve()
}

return request("metis_hermes_bootstrap",{}).then(data=>{
bootstrapLoaded=true
if(data && data.chat_session && data.chat_session.session_code){
sessionCode=String(data.chat_session.session_code)
}
hydrateHistory(data && Array.isArray(data.chat_history) ? data.chat_history : [])
}).catch(()=>{
if(messages.children.length < 1){
addMessage("Submit an operational request.","hermes")
}
})
}

function sendMessage(forcedText,forcedAction){

let text=(typeof forcedText==="string"?forcedText:input.value).trim()
let action=forcedAction || "metis_hermes_query"

if(!text) return

addMessage(text,"user")

input.value=""

request(action,{
query:text,
session_code:sessionCode
}).then(data=>{

if(data && data.session && data.session.session_code){
sessionCode=String(data.session.session_code)
}

// Keep rich response rendering (approval cards, previews, execution controls)
// for live query responses. History hydration is bootstrap-only fallback.
if(action==="metis_hermes_bootstrap" && data && Array.isArray(data.history) && data.history.length){
hydrateHistory(data.history)
return
}

if(action==="metis_hermes_diagnostics"){
renderDiagnosticResponse(data)
}else{
renderConversationResponse(data)
}

}).catch(error=>{

addMessage(error && error.message ? String(error.message) : "Hermes request failed.","hermes")

})

}

sendBtn.onclick=()=>sendMessage()

input.addEventListener("keydown",e=>{
if(e.key==="Enter") sendMessage()
})

if(testPulse){
testPulse.onclick=()=>{
pulse=14
sendMessage("diagnose permissions","metis_hermes_diagnostics")
}
}

if(testThink){
testThink.onclick=()=>{
thinking=!thinking
}
}

messages.addEventListener("click",e=>{
const previewBtn=e.target.closest("[data-hermes-preview]")
const approveBtn=e.target.closest("[data-hermes-approve]")
const executeBtn=e.target.closest("[data-hermes-execute]")
const revealBtn=e.target.closest("[data-hermes-reveal]")

if(previewBtn){
previewAction(String(previewBtn.getAttribute("data-hermes-preview")||""))
}

if(approveBtn){
approveAction(String(approveBtn.getAttribute("data-hermes-approve")||""))
}

if(executeBtn){
executeAction(String(executeBtn.getAttribute("data-hermes-execute")||""))
}

if(revealBtn){
revealSecret(String(revealBtn.getAttribute("data-hermes-reveal")||""))
}
})

const rings=[]
const particles=[]

function createRing(radiusRatio,segments,speed){

let arcs=[]

for(let i=0;i<segments;i++){

arcs.push({
start:Math.random()*Math.PI*2,
len:Math.random()*0.35+.05,
alpha:Math.random()*.4+.5
})

}

rings.push({
radius:ORB_SIZE*radiusRatio,
rotation:Math.random()*Math.PI*2,
speed:speed,
arcs
})

}

createRing(.38,34,.002)
createRing(.32,26,-.003)
createRing(.27,22,.0038)
createRing(.22,16,-.0048)

for(let i=0;i<24;i++){

particles.push({
angle:Math.random()*Math.PI*2,
radius:Math.random()*ORB_SIZE*.42,
speed:Math.random()*0.008+.002,
size:Math.max(1.2,ORB_SIZE*0.02)
})

}

function draw(){

ctx.clearRect(0,0,ORB_SIZE,ORB_SIZE)

hue+=thinking?.6:.15

if(thinking){
headerAvatar.classList.add("thinking")
}else{
headerAvatar.classList.remove("thinking")
}

document.documentElement.style.setProperty("--hermes-glow",hue)

drawRings()
drawParticles()
drawCore()

if(pulse>0) pulse-=.4

if(collapsing){

collapseRadius-=2

if(collapseRadius<=0){

collapsing=false
collapseRadius=ORB_SIZE

}

}

requestAnimationFrame(draw)

}

function drawRings(){

rings.forEach(r=>{

let spin=thinking?r.speed*7:r.speed

r.rotation+=spin

r.arcs.forEach(a=>{

let radius=collapsing?r.radius*(collapseRadius/ORB_SIZE):r.radius

ctx.strokeStyle=`hsla(${hue+(radius/ORB_SIZE)*120},95%,48%,${a.alpha})`

ctx.lineWidth=Math.max(2,ORB_SIZE*0.03)

ctx.beginPath()

ctx.arc(center,center,radius,a.start+r.rotation,a.start+a.len+r.rotation)

ctx.stroke()

})

})

}

function drawParticles(){

particles.forEach(p=>{

p.angle+=thinking?p.speed*3:p.speed

let radius=collapsing?p.radius*(collapseRadius/ORB_SIZE):p.radius

let x=center+Math.cos(p.angle)*radius
let y=center+Math.sin(p.angle)*radius

ctx.fillStyle=`hsl(${hue+40},100%,55%)`

ctx.beginPath()
ctx.arc(x,y,p.size,0,Math.PI*2)
ctx.fill()

})

}

function drawCore(){

let coreSize=Math.max(ORB_SIZE*.05,3)+pulse*.25

if(collapsing){
coreSize*=collapseRadius/ORB_SIZE
}

const g=ctx.createRadialGradient(center,center,0,center,center,ORB_SIZE*.1+pulse)

g.addColorStop(0,`hsl(${hue},100%,60%)`)
g.addColorStop(.4,`hsl(${hue+40},100%,52%)`)
g.addColorStop(1,`hsl(${hue+80},100%,45%)`)

ctx.fillStyle=g

ctx.beginPath()
ctx.arc(center,center,coreSize,0,Math.PI*2)
ctx.fill()

}

draw()

})
