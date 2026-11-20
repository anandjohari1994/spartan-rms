"""Map the revised reference's named placeholders to runtime PDF fields."""
import json
import re
from pathlib import Path

import pdfplumber

pages = []
value_fields = {'designation', 'department', 'work_location', 'employment_type', 'joining_date', 'commencement_date', 'months'}
aliases = {'months': 'probation_months'}

def entry(page, match, left, right, runs=None, **extra):
    return {'x':left, 'right':right, 'top':match['top'], 'bottom':match['bottom'],
            'baseline':page.height-match['chars'][0]['matrix'][5], 'size':match['chars'][0]['size'],
            'runs':runs or [{'key':aliases.get(match['key'],match['key']),'bold':'Bold' in match['chars'][0]['fontname']}], **extra}

with pdfplumber.open('resources/offer-letters/revised-reference.pdf') as doc:
    for page_index,page in enumerate(doc.pages):
        matches=page.search(r'\[[a-z0-9_]+\]') or []
        for m in matches:
            m['key']=m['text'][1:-1]
        entries=[]
        is_annexure='Annexure A: Salary Break-up' in page.extract_text()
        for line in page.extract_text_lines():
            on_line=[m for m in matches if abs(m['top']-line['top'])<1]
            if not on_line:
                continue
            if is_annexure and any(m['key'].endswith(('_monthly','_annual')) or m['key']=='other_label' for m in on_line):
                for m in on_line:
                    is_total=m['key'].startswith('gross_')
                    is_ctc=m['key'] in ['ctc_monthly','annual_ctc']
                    is_label=m['key']=='other_label'
                    right=286 if is_label else m['x1']
                    entries.append(entry(page,m,m['x0'] if is_label else right-100,right,
                        align='left' if is_label else 'right',
                        background=[33,94,168] if is_ctc else ([231,238,247] if is_total else [255,255,255]),
                        color=[255,255,255] if is_ctc else [0,0,0]))
                continue
            first=on_line[0]
            if first['key'] in ['notice_terms', 'working_terms', 'leave_policy']:
                max_lines = 6 if first['key']=='leave_policy' else 3
                block = entry(page,first,72.1,523.4,multiline=True,max_lines=max_lines)
                block['bottom'] += (max_lines-1)*14.4
                entries.append(block)
                continue
            if first['key'] in value_fields and 230 < first['x0'] < 250:
                trailing=line['text'].split(first['text'],1)[1]
                entries.append(entry(page,first,first['x0'],523.4,runs=[{'key':aliases.get(first['key'],first['key']),'bold':False},{'text':trailing,'bold':False}]))
                continue
            if any(m['key']=='signatory_name' for m in on_line):
                for m in on_line:
                    entries.append(entry(page,m,m['x0'],290 if m['x0']<300 else 523.4))
                continue
            if first['key'] in ['signatory_designation','signature_date']:
                runs = ([{'text':'Date: ','bold':False}] if first['key']=='signature_date' else [])
                runs.append({'key':first['key'],'bold':False})
                if first['key']=='signatory_designation':
                    runs.append({'text':', HR Department','bold':False})
                entries.append(entry(page,line,line['x0'],290,runs=runs))
                continue
            runs=[]
            mi=0
            for part in re.split(r'(\[[a-z0-9_]+\])',line['text']):
                if not part:
                    continue
                if part.startswith('['):
                    m=on_line[mi]; mi+=1
                    runs.append({'key':aliases.get(m['key'],m['key']),'bold':'Bold' in m['chars'][0]['fontname']})
                else:
                    runs.append({'text':part,'bold':'Bold' in line['chars'][0]['fontname'] and mi==0})
            # Full lines of justified paragraphs reach the right body margin;
            # preserve this spacing when replacing their variable text at runtime.
            align = 'right' if first['key']=='letter_date' else ('justify' if abs(line['x1']-523.4)<.2 and abs(line['x0']-72.1)<.2 else 'left')
            entries.append(entry(page,line,72.1 if first['key']=='letter_date' else line['x0'],523.4,runs=runs,align=align))
        spec={'width':page.width,'height':page.height,'entries':entries}
        optional=page.search(re.compile(r'4\.7 If you are not selected.*?applicable law\.',re.DOTALL))
        if optional:
            m=optional[0]
            spec['optional_clause']={'x':72.1,'top':m['top']-.5,'right':523.4,'bottom':m['bottom']+.5,
                                     'baseline':page.height-m['chars'][0]['matrix'][5],
                                     'text':' '.join(m['text'].split())}
        pages.append(spec)
assert sum('optional_clause' in page for page in pages)==1, 'Optional clause must occupy one page.'
Path('resources/offer-letters/layout.json').write_text(json.dumps(pages,indent=2),encoding='utf-8')
