"""Check the requested template edits in a generated PDF, including font weight."""
import re
import sys
import pdfplumber
from reportlab.lib.utils import ImageReader

with pdfplumber.open(sys.argv[1]) as doc:
    full='\n'.join(p.extract_text(x_tolerance=1) for p in doc.pages)
    normalized=' '.join(full.split())
    assert len(doc.pages) >= 6
    assert '("Company")' in normalized
    assert '("the Company")' not in normalized
    assert 'Less: Employee PF (estimated)' not in normalized
    assert 'after employee PF' not in normalized
    assert 'Reporting Manager' not in normalized
    assert 'Offer of Conditional Employment' not in normalized
    assert '[' not in full and ']' not in full, 'Unfilled or wrapped PDF placeholder'
    assert re.search(r'Professional Tax [\d,]+\.\d{2} [\d,]+\.\d{2}', normalized), 'Professional Tax amounts missing'
    assert normalized.index('4. Pending Client Interview') < normalized.index('4.1 You have successfully completed Round 1') < normalized.index('4.2 This letter is a conditional offer only') < normalized.index('5. Client Deployment')
    assert 'Clause 3 (Compensation)' in normalized
    assert '5.1 "Client billing' not in normalized and 'rounds."' not in normalized
    assert 'Completed Company joining and employee information forms;' not in normalized
    for removed in ['Employment Commencement Date', 'Onboarding and Documentation.', 'Document Submission', '[valid_until]', '[acceptance_deadline]']:
        assert removed not in normalized, removed
    for added in ['Estimated Net Salary', 'Professional Tax', 'Gross Salary', 'Cost to Company - CTC', 'within 24 hours of receiving it', '18 Leaves in a year (12 earned leaves + 6 Sick Leaves)', '1.5 days of leave', 'TRAINING AND DEVELOPMENT', 'JOB ROLES & RESPONSIBILITIES', 'ASSIGNMENT', 'TERMINATION', 'NO HIRE OF EMPLOYEES', 'five consecutive working days', 'Please maintain strict confidentiality of the terms and conditions']:
        assert added in normalized, added
    acceptance = normalized.split('8. Acceptance & Acknowledgement', 1)[1].split('We look forward', 1)[0]
    assert re.fullmatch(r'Please indicate your acceptance by signing and returning the duplicate copy of this letter\. By signing below, I, .+?, confirm that I have read and understood this letter\. I accept this conditional offer on the terms stated above\.', acceptance.strip()), acceptance
    assert not re.search(r'\([a-f]\)', acceptance), 'Obsolete acceptance items remain'
    confidentiality = 'This Offer Letter must not be shared, forwarded, copied, or disclosed to any third party or public platform without prior written authorization from Spartan Infosoft Pvt. Ltd. Unauthorized disclosure may result in appropriate disciplinary or legal action, subject to applicable laws.'
    assert normalized.count(confidentiality)==1, 'Confidentiality clause missing or duplicated'
    assert '6.22 TIME SHEETS' in normalized
    assert 'Any delays in the timesheet approval process will lead to delay in the payment of your salary.' in normalized
    regular_prefixes={'4.1','4.2','4.3','4.4','5.1'}
    checked=set()
    active=None
    for page in doc.pages:
        billing_lines=[]
        for line in page.crop((70,100,525,780)).extract_text_lines():
            label=re.match(r'^(\d\.\d|\([a-f]\)) ',line['text'])
            if label:
                active=label.group(1)
            elif re.match(r'^\d\. [A-Z]',line['text']):
                active=None
            if active in regular_prefixes:
                assert all('Bold' not in c['fontname'] for c in line['chars']), f'Unexpected bold: {line["text"]}'
                checked.add(active)
            if active=='5.1':
                billing_lines.append(line)
        if billing_lines:
            top=min(l['top'] for l in billing_lines)
            bottom=max(l['bottom'] for l in billing_lines)
            assert not [r for r in page.rects if r['top']<bottom and r['bottom']>top and r['x0']<525 and r['x1']>70], 'Billing paragraph still has a box'
    assert checked==regular_prefixes, checked
    for heading in ['6.8 Notice Period', '6.9 Working Hours and Days', '6.10 Leave Policy', '6.11 Certification Reimbursement:', '6.12 Health Insurance:', '6.13 Travel Allowance:', '6.14 No Employment Bond:', '6.15 Company Laptop:', '6.16 Data Confidentiality:']:
        assert heading in normalized, f'Missing employment term: {heading}'
    signature_size=ImageReader('resources/offer-letters/authorized-signature.png').getSize()
    signatures=[im for page in doc.pages for im in page.images if tuple(im['srcsize'])==signature_size]
    assert len(signatures)==1, 'Expected one authorized signature'
    signature=signatures[0]
    assert abs(signature['width']-132)<.1 and abs(signature['height']-132*signature_size[1]/signature_size[0])<.1, 'Distorted signature'
    assert signature['x0']>=72 and signature['x1']<298, 'Signature outside the authorized signatory column'
    for prefix in ['3.1 Your annual', '4.1 You have successfully', '5.1 Client billing']:
        line=next(line for page in doc.pages for line in page.extract_text_lines() if line['text'].startswith(prefix))
        assert abs(line['x0']-72.1)<.2 and abs(line['x1']-523.4)<.2, f'Paragraph not justified: {prefix}'
    print('Verified wording, filled placeholders, font weights, plain billing, justification and signature size/position.')
