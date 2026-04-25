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

function humanizeKey(value){
return String(value||"")
.replace(/[_-]+/g," ")
.replace(/\s+/g," ")
.trim()
.replace(/\b\w/g,letter=>letter.toUpperCase())
}

function formatHermesValue(value){
if(value==null){
return ""
}
if(typeof value==="string" || typeof value==="number" || typeof value==="boolean"){
return String(value)
}
if(Array.isArray(value)){
return value.map(formatHermesValue).filter(Boolean).join(", ")
}
if(typeof value==="object"){
if(value.message){
return String(value.message)
}
if(value.title){
return String(value.title)
}
if(value.check && value.status){
return `${humanizeKey(value.check)}: ${String(value.status).toUpperCase()}`
}
if(value.status){
return String(value.status)
}
try{
return JSON.stringify(value)
}catch(_error){
return ""
}
}
return String(value)
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

function encodeDatasetJson(value){
try{
return escapeHtml(JSON.stringify(value || {}))
}catch(_error){
return "{}"
}
}

function parseDatasetJson(value){
try{
const parsed=JSON.parse(String(value||"{}"))
return parsed && typeof parsed==="object" ? parsed : {}
}catch(_error){
return {}
}
}

function isHelpIssuePayload(value){
if(!value || typeof value!=="object"){
return false
}
return Boolean(
value.section_labels ||
value.formatted_response ||
value.response_mode ||
value.classification ||
(Array.isArray(value.steps) && value.steps.length) ||
(Array.isArray(value.checks) && value.checks.length) ||
(Array.isArray(value.admin_escalation) && value.admin_escalation.length) ||
(Array.isArray(value.guidance_links) && value.guidance_links.length) ||
(Array.isArray(value.related_articles) && value.related_articles.length)
)
}

function responseAnswerText(data,structured,helpResult){
if(helpResult && helpResult.summary){
return String(helpResult.summary)
}
if(structured && structured.result && typeof structured.result==="object" && structured.result.message){
return String(structured.result.message)
}
if(structured && structured.message){
return String(structured.message)
}
if(data && data.message){
return String(data.message)
}
if(data && data.reasoning && data.reasoning.answer){
return formatHermesValue(data.reasoning.answer)
}
return "Operation request received."
}

function buildConversationMarkup(data){
const structured=(data && data.reasoning && data.reasoning.structured && typeof data.reasoning.structured==="object"
? data.reasoning.structured
: (data && typeof data==="object" ? data : null))
const helpResult=structured && isHelpIssuePayload(structured.result) ? structured.result : (structured && isHelpIssuePayload(structured) ? structured : null)
const answer=responseAnswerText(data,structured,helpResult)
let html=`<div class="hermes-answer">${escapeHtml(answer)}</div>`

if(data && data.reasoning && data.reasoning.grounding && Array.isArray(data.reasoning.grounding.grounded) && data.reasoning.grounding.grounded.length){
html+=`<div class="hermes-grounding">Grounded in ${escapeHtml(data.reasoning.grounding.grounded.slice(0,3).map(g=>g.label).join(", "))}</div>`
}

if(structured && isHelpIssuePayload(structured.result)){
html+=renderHelpIssueResult(structured.result)
}else if(structured && structured.result){
html+=renderOperationResult(structured.result)
}else if(structured && isHelpIssuePayload(structured)){
html+=renderHelpIssueResult(structured)
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

return html
}

function restoreHistoryItem(item){
const role=String(item && item.role_name ? item.role_name : "hermes")
const content=String(item && item.content ? item.content : "")
const metadata=item && item.metadata && typeof item.metadata==="object" ? item.metadata : {}

if(role==="user"){
if(content){
addMessage(content,"user")
}
return
}

const structured=metadata && metadata.structured && typeof metadata.structured==="object" ? metadata.structured : null
if(structured){
addRichMessage(buildConversationMarkup({
reasoning:{
answer:String(metadata.answer || content || ""),
structured:structured
}
}),"hermes")
return
}

if(content){
addMessage(content,"hermes")
}
}

function hydrateHistory(history){
clearMessages()

if(!Array.isArray(history) || history.length < 1){
addMessage("Submit an operational request.","hermes")
return
}

history.forEach(item=>{
restoreHistoryItem(item)
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
addRichMessage(buildConversationMarkup(data),"hermes")
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

if(Array.isArray(result.checks) && result.checks.length){
let html=``
if(result.summary && typeof result.summary==="object"){
const summaryParts=[]
if(typeof result.summary.passed!=="undefined"){
summaryParts.push(`${Number(result.summary.passed||0)} passed`)
}
if(typeof result.summary.warnings!=="undefined"){
summaryParts.push(`${Number(result.summary.warnings||0)} warning(s)`)
}
if(typeof result.summary.failed!=="undefined"){
summaryParts.push(`${Number(result.summary.failed||0)} failed`)
}
if(summaryParts.length){
html+=`<div class="hermes-grounding">${escapeHtml(summaryParts.join(", "))}</div>`
}
}
html+=`<div class="hermes-findings">`
result.checks.forEach(check=>{
const label=humanizeKey(check && typeof check==="object" ? (check.check || check.name || check.title || "Check") : "Check")
const status=check && typeof check==="object" && check.status ? ` [${String(check.status).toUpperCase()}]` : ""
const detail=formatHermesValue(check && typeof check==="object" ? (check.message || check.detail || check.result || check) : check)
html+=`<div class="hermes-finding"><strong>${escapeHtml(label + status)}</strong><span>${escapeHtml(detail)}</span></div>`
})
html+=`</div>`
return html
}

if(result.status && result.message && typeof result.message==="string"){
let html=`<div class="hermes-grounding">${escapeHtml(result.message)}</div>`
const hiddenKeys=new Set(["status","message","enclave_request_id","release_status"])
const rows=Object.keys(result).filter(key=>!hiddenKeys.has(key) && result[key]!=null && typeof result[key]!=="object")
if(rows.length){
html+=`<div class="hermes-findings">`
rows.slice(0,8).forEach(key=>{
html+=`<div class="hermes-finding"><strong>${escapeHtml(humanizeKey(key))}</strong><span>${escapeHtml(formatHermesValue(result[key]))}</span></div>`
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

function renderHelpIssueResult(result){
if(!result || typeof result!=="object"){
return ""
}

let html=""
const labels=result.section_labels && typeof result.section_labels==="object" ? result.section_labels : {}
const stepsLabel=String(labels.steps || "Step-by-step fix")
const checksLabel=String(labels.checks || "Things to check")
const adminLabel=String(labels.admin || "When to contact an admin")
const articlesLabel=String(labels.articles || "Related help articles")

if(Array.isArray(result.guidance_links) && result.guidance_links.length){
html+=`<div class="hermes-grounding">Go to</div>`
html+=`<div class="hermes-action-buttons">`
result.guidance_links.slice(0,2).forEach(link=>{
const payload=encodeDatasetJson(link)
html+=`<button type="button" class="hermes-inline-btn primary" data-hermes-navigate="${payload}">${escapeHtml(String(link.label||"Go there"))}</button>`
if(link.walkthrough_id || link.highlight_selector){
html+=`<button type="button" class="hermes-inline-btn" data-hermes-guide="${payload}">Guide me</button>`
}
})
html+=`</div>`
}

if(Array.isArray(result.steps) && result.steps.length){
html+=`<div class="hermes-grounding">${escapeHtml(stepsLabel)}</div>`
html+=`<ol class="hermes-rich-list">`
result.steps.forEach(step=>{
html+=`<li>${escapeHtml(String(step))}</li>`
})
html+=`</ol>`
}

if(Array.isArray(result.checks) && result.checks.length){
html+=`<div class="hermes-grounding">${escapeHtml(checksLabel)}</div>`
html+=`<ul class="hermes-rich-list">`
result.checks.slice(0,6).forEach(check=>{
html+=`<li>${escapeHtml(formatHermesValue(check))}</li>`
})
html+=`</ul>`
}

if(Array.isArray(result.admin_escalation) && result.admin_escalation.length){
html+=`<div class="hermes-grounding">${escapeHtml(adminLabel)}</div>`
html+=`<ul class="hermes-rich-list">`
result.admin_escalation.slice(0,5).forEach(item=>{
html+=`<li>${escapeHtml(String(item))}</li>`
})
html+=`</ul>`
}

if(Array.isArray(result.related_articles) && result.related_articles.length){
html+=`<div class="hermes-grounding">${escapeHtml(articlesLabel)}</div>`
html+=`<div class="hermes-findings">`
result.related_articles.slice(0,5).forEach(article=>{
html+=`<div class="hermes-finding"><strong>${escapeHtml(String(article.title||"Article"))}</strong><span>${escapeHtml(String(article.summary||article.slug||""))}</span></div>`
})
html+=`</div>`
}

if(Array.isArray(result.proposed_actions) && result.proposed_actions.length){
html+=`<div class="hermes-grounding">Proposed actions</div>`
html+=`<div class="hermes-findings">`
result.proposed_actions.forEach(action=>{
html+=`<div class="hermes-finding"><strong>${escapeHtml(String(action.title||"Action"))}</strong><span>${escapeHtml(String(action.action_summary||"Requires approval before execution."))}</span></div>`
})
html+=`</div>`
}

return html
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

function refreshHelpContext(){
const shell=document.querySelector(".metis-view-shell")
if(!shell || !window.metisHelp){
return
}
window.metisHelp.current_topic=String(shell.getAttribute("data-metis-topic")||"")
window.metisHelp.current_domain=String(shell.getAttribute("data-metis-module")||"")
window.metisHelp.current_view=String(shell.getAttribute("data-metis-view")||"")
}

function refreshCoreUi(){
refreshHelpContext()
const main=document.getElementById("metis-main-content") || document
if(window.Metis && Metis.page && typeof Metis.page.init==="function"){
Metis.page.init(main,{
reason:"partial-navigation",
url:window.location.href
})
}else{
if(window.Metis && Metis.tabs && typeof Metis.tabs.init==="function"){
Metis.tabs.init(main)
}
if(window.Metis && Metis.modal && typeof Metis.modal.init==="function"){
Metis.modal.init(main)
}
if(window.Metis && Metis.inlineEdit && typeof Metis.inlineEdit.init==="function"){
Metis.inlineEdit.init(main)
}
}
if(window.Metis && Metis.help && typeof Metis.help.retagFallbackElements==="function"){
Metis.help.retagFallbackElements()
}
document.dispatchEvent(new CustomEvent("metis:navigation:loaded",{detail:{url:window.location.href}}))
}

function syncBodyClasses(doc){
if(!doc || !doc.body){
return
}
const keep=["metis-help-mode","metis-help-panel-open","metis-walkthrough-active"]
const next=new Set(String(doc.body.className||"").split(/\s+/).filter(Boolean))
keep.forEach(cls=>{
if(document.body.classList.contains(cls)){
next.add(cls)
}
})
document.body.className=Array.from(next).join(" ")
}

function assetFingerprint(node){
if(!node){
return ""
}
if(node.tagName==="LINK"){
return `link:${String(node.getAttribute("href")||"").trim()}`
}
if(node.tagName==="SCRIPT" && node.src){
return `script:${String(node.src||"").trim()}`
}
if(node.tagName==="SCRIPT"){
return `inline:${String(node.textContent||"").trim()}`
}
return ""
}

function shouldSyncHeadNode(node){
if(!node || !node.tagName){
return false
}
if(node.tagName==="LINK"){
const rel=String(node.getAttribute("rel")||"").toLowerCase()
const href=String(node.getAttribute("href")||"").trim()
return rel==="stylesheet" && href!=="" && (/\/assets\//.test(href) || /\/assets\/modules\//.test(href))
}
if(node.tagName==="SCRIPT"){
if(node.src){
return /\/assets\//.test(String(node.src||""))
}
const text=String(node.textContent||"").trim()
return text!=="" && /(metis[A-Z]|window\.metis|window\.Metis|const metis|var metis)/.test(text)
}
return false
}

function loadScriptNode(node){
return new Promise((resolve,reject)=>{
const script=document.createElement("script")
Array.from(node.attributes).forEach(attribute=>{
script.setAttribute(attribute.name,attribute.value)
})
script.onload=()=>resolve(true)
script.onerror=()=>reject(new Error("Module script failed to load."))
if(!node.src){
script.text=node.textContent || ""
document.head.appendChild(script)
resolve(true)
return
}
document.head.appendChild(script)
})
}

function syncHeadAssets(doc){
if(!doc || !doc.head){
return Promise.resolve(false)
}
const existing=new Set()
document.head.querySelectorAll('link[rel="stylesheet"][href],script[src],script:not([src])').forEach(node=>{
const fingerprint=assetFingerprint(node)
if(fingerprint){
existing.add(fingerprint)
}
})

const pending=[]
let addedModuleScript=false
doc.head.querySelectorAll("link[rel='stylesheet'][href],script").forEach(node=>{
if(!shouldSyncHeadNode(node)){
return
}
const fingerprint=assetFingerprint(node)
if(!fingerprint || existing.has(fingerprint)){
return
}
existing.add(fingerprint)
if(node.tagName==="LINK"){
const link=document.createElement("link")
Array.from(node.attributes).forEach(attribute=>{
link.setAttribute(attribute.name,attribute.value)
})
document.head.appendChild(link)
return
}
if(node.tagName==="SCRIPT"){
if(node.src && /\/assets\/modules\//.test(String(node.src||""))){
addedModuleScript=true
}
pending.push(loadScriptNode(node))
}
})

if(!pending.length){
return Promise.resolve(false)
}

return Promise.all(pending).then(()=>addedModuleScript)
}

function executeEmbeddedScripts(scope){
if(!scope){
return
}
scope.querySelectorAll("script").forEach(script=>{
const replacement=document.createElement("script")
Array.from(script.attributes).forEach(attribute=>{
replacement.setAttribute(attribute.name,attribute.value)
})
replacement.text=script.textContent || ""
script.parentNode.replaceChild(replacement,script)
})
}

function navigateWithinShell(targetUrl,options){
const target=String(targetUrl||"").trim()
const currentMain=document.getElementById("metis-main-content")
const opts=options && typeof options==="object" ? options : {}

if(!target){
return Promise.resolve(false)
}

if(!currentMain){
if(window.Metis && Metis.navigation && typeof Metis.navigation.go==="function"){
Metis.navigation.go(target)
}else{
window.location.assign(target)
}
return Promise.resolve(false)
}

return fetch(target,{
credentials:"same-origin",
headers:{
Accept:"text/html,application/xhtml+xml"
}
}).then(response=>{
if(!response.ok){
throw new Error("Unable to open that page right now.")
}
return response.text()
}).then(html=>{
const doc=new DOMParser().parseFromString(String(html||""),"text/html")
const nextMain=doc.querySelector("#metis-main-content")
if(!nextMain){
throw new Error("Target page could not be loaded in-place.")
}
return syncHeadAssets(doc).then(addedModuleScript=>{
currentMain.innerHTML=nextMain.innerHTML
syncBodyClasses(doc)
document.title=String(doc.title||document.title)

if(opts.replace){
window.history.replaceState({metisPartial:true},"",target)
}else{
window.history.pushState({metisPartial:true},"",target)
}

executeEmbeddedScripts(currentMain)
refreshCoreUi()

if(window.Metis && Metis.help && typeof Metis.help.focusTarget==="function"){
window.setTimeout(()=>{
Metis.help.focusTarget({
selector:String(opts.highlight_selector||"").trim(),
fallbackSelector:".metis-view-shell"
})
},120)
}

if(opts.walkthrough_id && window.Metis && Metis.walkthrough && typeof Metis.walkthrough.start==="function"){
window.setTimeout(()=>{
Metis.walkthrough.start(String(opts.walkthrough_id))
},260)
}

return true
})
}).catch(error=>{
if(window.Metis && Metis.toast && typeof Metis.toast.error==="function"){
Metis.toast.error(error && error.message ? String(error.message) : "Navigation failed.")
}
if(window.Metis && Metis.navigation && typeof Metis.navigation.go==="function"){
Metis.navigation.go(target)
}else{
window.location.assign(target)
}
return false
})
}

function triggerGuidance(payload,guideMode){
const target=payload && typeof payload==="object" ? payload : {}
return navigateWithinShell(String(target.url||""),{
highlight_selector:String(target.highlight_selector||""),
walkthrough_id:guideMode ? String(target.walkthrough_id||"") : "",
replace:false
})
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

function currentHermesContext(){
const shell=document.querySelector(".metis-view-shell")
const moduleKey=shell ? String(shell.getAttribute("data-metis-module")||"").trim() : ""
const topic=shell ? String(shell.getAttribute("data-metis-topic")||"").trim() : ""
return {
current_route:String(window.location.pathname||"").trim(),
current_module:moduleKey,
current_topic:topic
}
}

function sendMessage(forcedText,forcedAction){

let text=(typeof forcedText==="string"?forcedText:input.value).trim()
let action=forcedAction || "metis_hermes_query"

if(!text) return

addMessage(text,"user")

input.value=""

const context=currentHermesContext()

request(action,{
query:text,
session_code:sessionCode,
current_route:context.current_route,
current_module:context.current_module,
current_topic:context.current_topic
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
const navigateBtn=e.target.closest("[data-hermes-navigate]")
const guideBtn=e.target.closest("[data-hermes-guide]")

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

if(navigateBtn){
triggerGuidance(parseDatasetJson(navigateBtn.getAttribute("data-hermes-navigate")||"{}"),false)
}

if(guideBtn){
triggerGuidance(parseDatasetJson(guideBtn.getAttribute("data-hermes-guide")||"{}"),true)
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
