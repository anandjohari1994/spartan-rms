import json, re
from pathlib import Path
import pdfplumber

source = Path('resources/offer-letters/reference.pdf')
keys = [
    ['reference', 'address_line_1', 'address_line_2', 'state', 'pin_code', 'letter_date', 'candidate_name', 'designation', 'department', 'designation', 'department', 'work_location', 'reporting_manager', 'employment_type', 'joining_date', 'commencement_date', 'probation_months', 'document_deadline'],
    ['annual_ctc', 'monthly_gross', 'salary_payment_day'],
    ['valid_until', 'acceptance_deadline', 'candidate_name'],
    ['signatory_name', 'candidate_name', 'signatory_designation', 'signature_date'],
    ['designation'] + [f'{k}_{period}' for k in ['basic', 'hra', 'lta', 'medical', 'conveyance', 'special'] for period in ['monthly', 'annual']] + ['other_label', 'other_monthly', 'other_annual', 'gross_monthly', 'gross_annual', 'benefits_monthly', 'benefits_annual', 'ctc_monthly', 'annual_ctc'],
]
pages = []
with pdfplumber.open(source) as doc:
    for page_index, page in enumerate(doc.pages):
        matches = [m for m in page.search(r'\[[^\]]+\]') if not m['text'].startswith('[OPTIONAL')]
        assert len(matches) == len(keys[page_index]), (page_index, len(matches))
        for match, key in zip(matches, keys[page_index]):
            match['key'] = key
        entries = []
        for line in page.extract_text_lines():
            on_line = [m for m in matches if abs(m['top'] - line['top']) < 1]
            if not on_line:
                continue
            if page_index == 0 and 420 < line['top'] < 615:
                # Keep the original label column and replace only its value column.
                m = on_line[0]
                trailing = line['text'].split(m['text'], 1)[1]
                entries.append({'x':m['x0'], 'right':523.9, 'top':m['top'], 'bottom':m['bottom'], 'baseline':page.height-m['chars'][0]['matrix'][5], 'size':m['chars'][0]['size'], 'runs':[{'key':m['key'],'bold':False}, {'text':trailing,'bold':False}]})
                continue
            if page_index == 4 and line['top'] > 150:
                for m in on_line:
                    is_total = m['key'] in ['gross_monthly', 'gross_annual']
                    is_ctc = m['key'] in ['ctc_monthly', 'annual_ctc']
                    entries.append({'x': m['x0'] if m['key'] == 'other_label' else m['x1']-100, 'right': m['x1'] if m['key'] != 'other_label' else 285, 'top': m['top'], 'bottom': m['bottom'], 'baseline': page.height - m['chars'][0]['matrix'][5], 'size': m['chars'][0]['size'], 'align': 'left' if m['key']=='other_label' else 'right', 'background': [33, 94, 168] if is_ctc else ([231,238,247] if is_total else [255,255,255]), 'color': [255,255,255] if is_ctc else [0,0,0], 'runs': [{'key':m['key'], 'bold': 'Bold' in m['chars'][0]['fontname']}]})
                continue
            parts = re.split(r'(\[[^\]]+\])', line['text'])
            runs = []
            mi = 0
            for part in parts:
                if not part:
                    continue
                if part.startswith('['):
                    m=on_line[mi]; mi+=1
                    runs.append({'key':m['key'], 'bold':'Bold' in m['chars'][0]['fontname']})
                else:
                    runs.append({'text':part, 'bold': 'Bold' in line['chars'][0]['fontname'] and mi == 0})
            # Signature columns are handled separately to preserve the acceptance block.
            if page_index == 3 and len(on_line)==2:
                for m in on_line:
                    entries.append({'x': m['x0'], 'right': 290 if m['x0']<300 else 523.4, 'top':m['top'], 'bottom':m['bottom'], 'baseline':page.height-m['chars'][0]['matrix'][5], 'size':10, 'runs':[{'key':m['key'],'bold':True}]})
                continue
            right = 290 if page_index == 3 else 523.9
            # Preserve signature/date text in the second column.
            if page_index == 3:
                runs = runs[:2] if on_line[0]['key'] == 'signature_date' else runs[:1]
                if on_line[0]['key']=='signatory_designation': runs.append({'text':', HR Department','bold':False})
            entries.append({'x':line['x0'], 'right':right, 'top':line['top'], 'bottom':line['bottom'], 'baseline':page.height-line['chars'][0]['matrix'][5], 'size':line['chars'][0]['size'], 'align':'right' if page_index==0 and on_line[0]['key']=='letter_date' else 'left', 'runs':runs})
        pages.append({'width':page.width,'height':page.height,'entries':entries})
Path('resources/offer-letters/layout.json').write_text(json.dumps(pages,indent=2),encoding='utf-8')
