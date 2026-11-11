import json
from pathlib import Path
import pymupdf

document = pymupdf.open('resources/offer-letters/reference.pdf')
layout = json.loads(Path('resources/offer-letters/layout.json').read_text())
for index, spec in enumerate(layout):
    page=document[index]
    for e in spec['entries']:
        page.add_redact_annot(pymupdf.Rect(e['x']-.15,e['top']-.5,e['right']+.15,e['bottom']+.5),fill=False)
    if index==1:
        page.add_redact_annot(pymupdf.Rect(71.9,587.4,524.1,628),fill=False)
    page.apply_redactions(images=0, graphics=0)
document.save('resources/offer-letters/conditional-offer.pdf',garbage=4,deflate=True)
print('Prepared template without underlying placeholder text.')
