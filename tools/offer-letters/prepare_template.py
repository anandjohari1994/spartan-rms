import json
from pathlib import Path
import pymupdf

document = pymupdf.open('resources/offer-letters/revised-reference.pdf')
layout = json.loads(Path('resources/offer-letters/layout.json').read_text())
for index, spec in enumerate(layout):
    page=document[index]
    for e in spec['entries']:
        page.add_redact_annot(pymupdf.Rect(e['x']-.15,e['top']-.5,e['right']+.15,e['bottom']+.5),fill=False)
    if 'optional_clause' in spec:
        e=spec['optional_clause']
        page.add_redact_annot(pymupdf.Rect(e['x']-.15,e['top'],e['right']+.15,e['bottom']),fill=False)
    page.apply_redactions(images=0, graphics=0)
document.save('resources/offer-letters/conditional-offer.pdf',garbage=4,deflate=True)
print('Prepared template without underlying placeholder text.')
