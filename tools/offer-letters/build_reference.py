"""Reflow the user-approved November 2026 edits onto the original letterhead.

Run from the repository root with reportlab, pdfplumber and PyMuPDF installed.
The supplied reference.pdf is never changed. Wording is read from that source;
only the requested edits, section numbering and cross-references are changed.
"""
import io
import re
from pathlib import Path
from xml.sax.saxutils import escape

import pdfplumber
import pymupdf
from reportlab.lib import colors
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.utils import ImageReader
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle, PageBreak, Image, KeepTogether

ROOT = Path('resources/offer-letters')
WIDTH, HEIGHT = 595.304, 841.89
LEFT, RIGHT = 72.1, 523.4
BODY_WIDTH = RIGHT - LEFT
BLUE = colors.HexColor('#215ea8')
for name, filename in [('Carlito', 'Carlito-Regular.ttf'), ('Carlito-Bold', 'Carlito-Bold.ttf')]:
    pdfmetrics.registerFont(TTFont(name, str(Path('resources/fonts/unifont') / filename)))
pdfmetrics.registerFontFamily('Carlito', normal='Carlito', bold='Carlito-Bold')

with pdfplumber.open(ROOT / 'reference.pdf') as source:
    # Exclude the repeated header, footer and page number.
    source_text = '\n'.join(page.crop((70, 100, 525, 780)).extract_text() for page in source.pages)

def original_clause(number):
    match = re.search(rf'(?ms)^{re.escape(number)} (.*?)(?=^\d\.\d |^\d\. [A-Z]|\Z)', source_text)
    if not match:
        raise ValueError(f'Missing original clause {number}')
    return ' '.join(match.group(1).split())

body = ParagraphStyle('Body', fontName='Carlito', fontSize=10.5, leading=14.4, spaceAfter=8, alignment=4)
heading = ParagraphStyle('Heading', parent=body, fontName='Carlito-Bold', fontSize=12, textColor=BLUE, spaceBefore=8, spaceAfter=8, keepWithNext=True, alignment=0)
title = ParagraphStyle('Title', parent=body, fontName='Carlito-Bold', fontSize=14, alignment=1, spaceBefore=12, spaceAfter=14, keepWithNext=True)
cell = ParagraphStyle('Cell', parent=body, fontSize=10, leading=12.2, spaceAfter=0, alignment=0)
story = []

def p(text, style=body):
    story.append(Paragraph(text, style))

def h(text):
    p(escape(text), heading)

def clause(number, text, bold_label=None):
    text = escape(text)
    if bold_label:
        text = text.replace(escape(bold_label), '<b>'+escape(bold_label)+'</b>', 1)
    for key in ['annual_ctc','monthly_gross','salary_payment_day','document_deadline','valid_until','acceptance_deadline']:
        text = text.replace('['+key+']','<b>['+key+']</b>')
    if number == '3.1':
        text = text.replace('Annexure A.', '<b>Annexure A.</b>')
    p(number+' '+text)

def details_table(rows):
    table = Table([[Paragraph('<b>'+escape(label)+'</b>', cell), Paragraph(value, cell)] for label, value in rows], colWidths=[164.95, BODY_WIDTH-164.95], hAlign='LEFT')
    table.setStyle(TableStyle([('LEFTPADDING',(0,0),(-1,-1),0),('RIGHTPADDING',(0,0),(-1,-1),0),('TOPPADDING',(0,0),(-1,-1),0),('BOTTOMPADDING',(0,0),(-1,-1),5.6),('VALIGN',(0,0),(-1,-1),'TOP')]))
    story.append(table)

p('Ref. No.: [reference]<br/>Address: [address_line_1],<br/>[address_line_2],<br/>[state] - [pin_code]', ParagraphStyle('Address', parent=body, leading=12.8, spaceAfter=0, alignment=0))
p('Date: [letter_date]', ParagraphStyle('Date', parent=body, alignment=2))
p('CONDITIONAL OFFER OF EMPLOYMENT', title)
p('Dear <b>[candidate_name]</b>,')
intro = re.search(r'(?s)Congratulations.*?(?=\n1\. Offer)', source_text).group(0)
intro = ' '.join(intro.split()).replace('("the Company")', '("Company")')
intro = escape(intro).replace('[Designation]', '<b>[designation]</b>').replace('[Department]', '<b>[department]</b>').replace('a conditional basis.', 'a <b>conditional basis.</b>')
p(intro)

h('1. Position & Reporting')
details_table([('Designation:', '[designation]'), ('Department:', '[department]'), ('Work Location:', '[work_location]'), ('Employment Type:', '[employment_type]')])
h('2. Joining Details')
details_table([('Proposed Date of Joining:', '[joining_date]'), ('Probation Period:', '[months] months from the date of joining')])
clause('2.1', original_clause('3.2'))

h('3. Compensation')
for i in range(1, 6):
    text = original_clause(f'4.{i}').replace('Clause 3', 'Clause 2')
    for old, new in [('Annual CTC','annual_ctc'), ('Monthly Gross Salary','monthly_gross'), ('Salary Payment Date','salary_payment_day')]:
        text = text.replace('['+old+']','['+new+']')
    if i == 3:
        text = 'Your salary shall accrue from your actual date of joining, in accordance with this letter and the Employment Agreement.'
    clause(f'3.{i}', text)
clause('3.6', 'Your estimated monthly Net Salary after employee PF is INR [net_monthly]. TDS, Professional Tax and other applicable payroll deductions are not included in this estimate. Refer to Annexure A.')

h('4. Pending Client Interview & Deployment Conditions')
clause('4.1', original_clause('1.1')+' You will be presented to the client for final evaluation as per the client\'s hiring requirements.')
clause('4.2', original_clause('1.2'))
for i in range(2, 6):
    clause(f'4.{i+1}', original_clause(f'5.{i}'))
optional = original_clause('5.6').split('ISSUE: ', 1)[1].removesuffix(']')
clause('4.7', optional)

h('5. Client Deployment & Billing Conditions')
for i in range(1, 5):
    clause(f'5.{i}', original_clause(f'6.{i}').strip('"'))

h('6. Conditions of Employment')
p('This offer and your continued employment are subject to the following:')
for i in range(1, 8):
    text = original_clause(f'7.{i}').replace('the Employment Commencement Date', 'your date of joining')
    label = text.split(':', 1)[0]+':' if ':' in text else 'Employment Agreement:'
    clause(f'6.{i}', text, label)

# Reserve complete paragraphs so editable employment policies wrap at runtime.
# Keep each label and its reserved body together across page breaks.
for label, key, lines in [('6.8 Notice Period', 'notice_terms', 3), ('6.9 Working Hours and Days', 'working_terms', 3), ('6.10 Leave Policy', 'leave_policy', 6)]:
    story.append(KeepTogether([
        Paragraph('<b>'+label+'</b>', ParagraphStyle('TermLabel', parent=body, spaceAfter=1)),
        Paragraph('['+key+']', ParagraphStyle('TermBody', parent=body, spaceAfter=0)),
        Spacer(1, (lines-1)*14.4+4),
    ]))

for number, label, text in [
    ('6.11', 'Certification Reimbursement:', 'The company will reimburse eligible professional certification expenses as per company policy.'),
    ('6.12', 'Health Insurance:', 'Health insurance coverage will be provided as per company policy.'),
    ('6.13', 'Travel Allowance:', 'Travel allowance will be provided as per company policy.'),
    ('6.14', 'No Employment Bond:', 'No employment bond or contractual lock-in is applicable.'),
    ('6.15', 'Company Laptop:', 'The company will deliver a laptop to your registered address for official client-related work only. The laptop must be used exclusively for authorized business purposes.'),
    ('6.16', 'Data Confidentiality:', 'All company and client information, documents, credentials, and data must remain strictly confidential and must not be shared, disclosed, or uploaded to any third party or public platform without prior written authorization.'),
]:
    clause(number, label+' '+text, label)

h('6.17 TRAINING AND DEVELOPMENT')
p('During the course of your employment, the Company may, at its discretion, provide or require you to undergo training programs to enhance your skills, performance, or compliance with operational and regulatory standards. You agree to participate in such training as and when required, and to adhere to all policies, procedures, and terms applicable to such training, including any service commitments, cost recovery, or certification requirements, as may be communicated by the Company from time to time. You will also undergo mandatory training programmes on a regular basis every year. Please complete the training programme within the timelines as communicated from time to time.')
h('6.18 JOB ROLES & RESPONSIBILITIES')
p('You shall be responsible for the performance of the functions expected of your role and any additional functions and duties that may be assigned to you in connection with the business and operations of the Company. You shall use the best of your efforts to promote, develop and extend the business of the Company and comply with the directions and regulations of the Company at all times, and in all respects.')
h('6.19 ASSIGNMENT')
p('You shall acknowledge that the services to be rendered by you are unique and personal. During your service with the Company, you shall not assign any of the rights or delegate any of the duties or obligations under this Agreement without the prior written consent of the Company.')
h('6.20 TERMINATION')
for index, text in enumerate([
    'During probation, either the employee or company may terminate employment by serving the notice period as per company policy.',
    'Employees must provide written notice or pay basic salary in lieu of the applicable notice period.',
    'The company may terminate employment by providing the required notice period or basic salary in lieu of notice.',
    'The company may terminate employment immediately in cases of misconduct, fraud, policy violations, dishonesty, negligence, or other serious violations.',
    'Absence without prior approval for five consecutive working days may result in termination.',
    'Upon termination, employees must return all company assets, documents, records, and confidential information.',
], 1):
    p(str(index)+'. '+escape(text))
h('6.21 NO HIRE OF EMPLOYEES')
p('During the period commencing on the date you join the Company and until six (6) months after cessation of your employment with the Company, for any reason what so ever, you agree that you shall not, either on your own account or for any corporation, limited liability Company, partnership or other entity or person, solicit any employee of the Company or any subsidiary of the Company to leave his or her employment, induce or attempt to induce any such employee to terminate or breach his or her employment agreement, if any, or hire any employee or former employee of the Company or any subsidiary of the Company.')

h('6.22 TIME SHEETS')
p('You are required to follow client\'s specific timesheet process as per the timelines. In the absence of client\'s specific process, you will receive Timesheet alert email with template along with the guidelines from your Timesheet SPOC at the Company (Spartan). This has to be filled and approved by your manager over the email and it should reach the SPOC along with attachment before the timeline as briefed by the Company HR. Any delays in the timesheet approval process will lead to delay in the payment of your salary.')

h('7. Conditional Offer Validity')
for i in range(1, 4):
    text = original_clause(f'8.{i}').replace('[Validity Date]', '[valid_until]').replace('[Acceptance Deadline]', '[acceptance_deadline]')
    if i == 1:
        text = 'Please accept this offer within 24 hours of receiving it by signing and returning a copy of this letter.'
    if i == 3:
        text = 'This Offer Letter must not be shared, forwarded, copied, or disclosed to any third party or public platform without prior written authorization from Spartan Infosoft Pvt. Ltd. Unauthorized disclosure may result in appropriate disciplinary or legal action, subject to applicable laws.'
    clause(f'7.{i}', text)

clause('7.4', 'Please maintain strict confidentiality of the terms and conditions of your employment. The Company takes a very serious view of such disclosures, and you will be liable for disciplinary action in case of breach of this condition of service.')

acceptance_start = len(story)
h('8. Acceptance & Acknowledgement')
p('Please indicate your acceptance by signing and returning the duplicate copy of this letter. By signing below, I, <b>[candidate_name]</b>, confirm that I have read and understood this letter.')
p('I accept this conditional offer on the terms stated above.')
p('We look forward to welcoming you to the Spartan Infosoft team and are confident that you will find your association with us both professionally rewarding and personally fulfilling.')
p('Yours sincerely,', ParagraphStyle('SignatureIntro', parent=body, keepWithNext=True))
signature_path = str(ROOT / 'authorized-signature.png')
signature_width, signature_height = ImageReader(signature_path).getSize()
sign_image = Image(signature_path, width=132, height=132 * signature_height / signature_width, hAlign='LEFT')
signature = Table([
    [Paragraph('<b>For Spartan Infosoft Pvt. Ltd.</b>',cell),Paragraph("<b>Candidate's Acceptance</b>",cell)],
    [sign_image, ''],
    [Paragraph('<b>[signatory_name]</b>',cell),Paragraph('<b>[candidate_name]</b>',cell)],
    [Paragraph('[signatory_designation], HR Department',cell),Paragraph('Signature: ____________________',cell)],
    [Paragraph('Date: [signature_date]',cell),Paragraph('Date: __________  Place: __________',cell)],
], colWidths=[BODY_WIDTH/2]*2, rowHeights=[20,sign_image.drawHeight+5,18,16,16], hAlign='LEFT', splitByRow=False)
signature.setStyle(TableStyle([('VALIGN',(0,0),(-1,-1),'TOP'),('LEFTPADDING',(0,0),(-1,-1),5.4),('TOPPADDING',(0,0),(-1,-1),0)]))
story.append(signature)
story[acceptance_start:] = [KeepTogether(story[acceptance_start:])]

story.append(PageBreak())
p('Annexure A: Salary Break-up', ParagraphStyle('Annexure',parent=heading,fontName='Times-Roman',fontSize=16,spaceBefore=0,spaceAfter=10))
p('Compensation structure referred to in Clause 3 (Compensation) above, for the position of <b>[designation]</b>. Both monthly and annual amounts are shown for each component. All amounts are in INR.')
rows = [[Paragraph('<b>Salary Component</b>',cell),Paragraph('<b>Monthly (INR)</b>',ParagraphStyle('HeaderRight',parent=cell,alignment=2)),Paragraph('<b>Annual (INR)</b>',ParagraphStyle('HeaderRight',parent=cell,alignment=2))]]
components = [('Basic Salary','basic'),('House Rent Allowance (HRA)','hra'),('Leave Travel Allowance (LTA)','lta'),('Medical Allowance','medical'),('Conveyance Allowance','conveyance'),('Special Allowance','special'),('[other_label]','other'),('Gross Salary','gross'),("Employer's PF Contribution / Other Benefits, if applicable",'benefits'),('Cost to Company - CTC','ctc'),('Less: Employee PF (estimated)','employee_pf'),('Estimated Net Salary (after employee PF)','net')]
for label,key in components:
    amounts = [f'[{key}_monthly]', '[annual_ctc]' if key=='ctc' else f'[{key}_annual]']
    bold = key in ['gross','ctc','net']
    rows.append([Paragraph(('<b>'+escape(value)+'</b>') if bold else escape(value), ParagraphStyle('SalaryCell',parent=cell,alignment=0 if col==0 else 2)) for col,value in enumerate([label,*amounts])])
salary = Table(rows,colWidths=[220.5,115.4,115.4],hAlign='LEFT')
salary.setStyle(TableStyle([('BACKGROUND',(0,0),(-1,0),BLUE),('BACKGROUND',(0,10),(-1,10),BLUE),('BACKGROUND',(0,8),(-1,8),colors.HexColor('#e7eef7')),('GRID',(0,0),(-1,-1),.4,colors.HexColor('#b9c9e2')),('TOPPADDING',(0,0),(-1,-1),5.5),('BOTTOMPADDING',(0,0),(-1,-1),5.5),('LEFTPADDING',(0,0),(-1,-1),5.4),('RIGHTPADDING',(0,0),(-1,-1),5.4),('VALIGN',(0,0),(-1,-1),'TOP')]))
for row in [0,10]:
    for para in rows[row]:
        para.style = ParagraphStyle('WhiteCell',parent=para.style,textColor=colors.white)
        # Paragraph fragments already contain the initial text color.
        for frag in para.frags:
            frag.textColor=colors.white
story.append(salary)
story.append(Spacer(1,18))
p('Notes:')
for item in [
    'Estimated Net Salary = Gross Salary less employee PF under the configured salary policy. TDS, Professional Tax and other applicable payroll deductions are excluded; actual take-home pay may differ.',
    'Salary is subject to statutory deductions (TDS, Provident Fund, Professional Tax and others, as applicable).',
    'Components may be revised in accordance with Company policy and applicable law.',
    'No client billing component forms part of, or affects, the salary structure above.',
]:
    paragraph = Paragraph(escape(item),ParagraphStyle('Note',parent=body,leftIndent=26.5,bulletIndent=13,bulletFontName='Carlito',spaceAfter=4),bulletText='\u2022')
    story.append(paragraph)
story.append(Spacer(1,14))
p('We wish you the very best and look forward to a long and successful association.')

buffer=io.BytesIO()
doc=SimpleDocTemplate(buffer,pagesize=(WIDTH,HEIGHT),leftMargin=LEFT-6,rightMargin=WIDTH-RIGHT-6,topMargin=104,bottomMargin=66)
def page_number(canvas, doc):
    canvas.setFont('Carlito',10.5)
    canvas.drawRightString(RIGHT,HEIGHT-800,str(doc.page))
doc.build(story,onFirstPage=page_number,onLaterPages=page_number)

# Keep the original vector logo, rule, company heading and footer. Remove its body
# rather than merely covering it, so hidden obsolete clauses cannot be extracted.
original=pymupdf.open(ROOT/'reference.pdf')
letterhead=pymupdf.open()
letterhead.insert_pdf(original,from_page=0,to_page=0)
letterhead[0].add_redact_annot(pymupdf.Rect(65,100,530,804),fill=False)
letterhead[0].apply_redactions(images=0,graphics=2)
body_pdf=pymupdf.open(stream=buffer.getvalue(),filetype='pdf')
result=pymupdf.open()
for index in range(len(body_pdf)):
    page=result.new_page(width=WIDTH,height=HEIGHT)
    page.show_pdf_page(page.rect,letterhead,0)
    page.show_pdf_page(page.rect,body_pdf,index)
result.save(ROOT/'revised-reference.pdf',garbage=4,deflate=True)
print(f'Created revised reference: {len(result)} pages.')
