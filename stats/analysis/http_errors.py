from datetime import datetime
import random
import requests

urls = [
    "http://play-cricket.com/api/v2/result_summary.json?api_token=cd3d9f47cef70496b9b3bfbab5231214&site_id=8087&season=2018&from_entry_date=01/01/2018",
    "http://play-cricket.com/api/v2/match_detail.json?api_token=cd3d9f47cef70496b9b3bfbab5231214&match_id=3339344",
    "http://play-cricket.com/api/v2/match_detail.json?api_token=cd3d9f47cef70496b9b3bfbab5231214&match_id=3339344",
    "http://play-cricket.com/api/v2/match_detail.json?api_token=cd3d9f47cef70496b9b3bfbab5231214&match_id=3325251",
    "http://play-cricket.com/api/v2/match_detail.json?api_token=cd3d9f47cef70496b9b3bfbab5231214&match_id=3641490",
    "http://play-cricket.com/api/v2/match_detail.json?api_token=cd3d9f47cef70496b9b3bfbab5231214&match_id=3670451",
    "http://play-cricket.com/api/v2/match_detail.json?api_token=cd3d9f47cef70496b9b3bfbab5231214&match_id=3670663",
    "http://play-cricket.com/api/v2/match_detail.json?api_token=cd3d9f47cef70496b9b3bfbab5231214&match_id=3325276",
    "http://play-cricket.com/api/v2/match_detail.json?api_token=cd3d9f47cef70496b9b3bfbab5231214&match_id=3672179",
    "http://play-cricket.com/api/v2/match_detail.json?api_token=cd3d9f47cef70496b9b3bfbab5231214&match_id=3677828",
    "http://play-cricket.com/api/v2/match_detail.json?api_token=cd3d9f47cef70496b9b3bfbab5231214&match_id=3677829",
    "http://play-cricket.com/api/v2/match_detail.json?api_token=cd3d9f47cef70496b9b3bfbab5231214&match_id=3325298",
    "http://play-cricket.com/api/v2/match_detail.json?api_token=cd3d9f47cef70496b9b3bfbab5231214&match_id=3680032",
    "http://play-cricket.com/api/v2/match_detail.json?api_token=cd3d9f47cef70496b9b3bfbab5231214&match_id=3325310",
    "http://play-cricket.com/api/v2/match_detail.json?api_token=cd3d9f47cef70496b9b3bfbab5231214&match_id=3682586"
]

request_count = 0
while True:
    url_index = random.randint(0, len(urls) - 1)
    url = urls[url_index]
    request_count += 1
    print(f"{datetime.utcnow()}  Request {request_count}")
    r = requests.get(url, headers={'Cache-Control': 'no-cache'})
    if r.status_code != 200:
        print("ERROR")
        print(f"  URL: {url}")
        print(f"  Status: {r.status_code}")
        print(f"  Text: {r.text}")
        print(f"  Headers: {r.headers}")