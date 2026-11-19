"""Check the requested template edits in a generated PDF, including font weight."""
import re
import sys
import pdfplumber
from reportlab.lib.utils import ImageReader

with pdfplumber.open(sys.argv[1]) as doc:
    full='\n'.join(p.extract_text() for p in doc.pages)
    normalized=' '.join(full.split())
    assert len(doc.pages)==5
    assert '("Company")' in normalized
    assert '("the Company")' not in normalized
    assert 'Reporting Manager' not in normalized
    assert 'Offer of Conditional Employment' not in normalized
    assert not re.search(r'\[[a-z0-9_]+\]',full), 'Unfilled PDF placeholder'
    assert normalized.index('4. Pending Client Interview') < normalized.index('4.1 You have successfully completed Round 1') < normalized.index('4.2 This letter is a conditional offer only') < normalized.index('5. Client Deployment')
    assert 'Clause 2' in normalized and 'Clause 3 (Compensation)' in normalized
    assert '5.1 "Client billing' not in normalized and 'rounds."' not in normalized
    assert normalized.count('Completed Company joining and employee information forms;')==1
    regular_prefixes={'4.1','4.2','4.3','4.4','5.1','(a)','(b)','(c)','(d)'}
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
