#!/usr/bin/env python3
"""Download the documented local-demo venue assets; does not access any database."""
import hashlib
import json
from pathlib import Path
import urllib.request

root = Path(__file__).resolve().parents[1]
for venue in json.loads((root / 'database/fixtures/billing-demo.json').read_text()):
    for kind, asset in venue['assets'].items():
        target = root / 'storage/app/public' / asset['path']
        if target.exists() and hashlib.sha256(target.read_bytes()).hexdigest() == asset['sha256']:
            continue
        request = urllib.request.Request(asset['source'], headers={'User-Agent': 'Mozilla/5.0'})
        with urllib.request.urlopen(request, timeout=45) as response:
            if not response.headers.get_content_type().startswith('image/'):
                raise RuntimeError('Expected image: ' + asset['source'])
            data = response.read(12_000_000)
        if hashlib.sha256(data).hexdigest() != asset['sha256']:
            raise RuntimeError('Source asset changed; review before replacing: ' + asset['source'])
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)
        print(venue['key'], kind, len(data), 'bytes')
print('All 20 venue assets are present and verified.')
