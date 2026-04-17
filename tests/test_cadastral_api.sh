#!/bin/bash

# Test the cadastral API endpoints
echo "=== Testing cadastral API endpoints ==="

# Function to format JSON output
format_json() {
    if command -v jq &> /dev/null; then
        jq '.'
    else
        cat
    fi
}

# Test the dedicated cadastral API endpoint
echo -e "\n=== 1. Testing dedicated cadastral API endpoint (/api/cadastral) ==="

echo -e "\n--- Testing with a valid cadastral reference (AB123) ---"
curl -s -X POST -H "Content-Type: application/json" -d '{"reference":"AB123"}' http://localhost:8000/api/cadastral | format_json

echo -e "\n--- Testing with a different valid cadastral reference (XY789) ---"
curl -s -X POST -H "Content-Type: application/json" -d '{"reference":"XY789"}' http://localhost:8000/api/cadastral | format_json

echo -e "\n--- Testing with an invalid cadastral reference format ---"
curl -s -X POST -H "Content-Type: application/json" -d '{"reference":"INVALID"}' http://localhost:8000/api/cadastral | format_json

echo -e "\n--- Testing with an empty cadastral reference ---"
curl -s -X POST -H "Content-Type: application/json" -d '{"reference":""}' http://localhost:8000/api/cadastral | format_json

# Test the search API endpoint with cadastral references
echo -e "\n=== 2. Testing search API endpoint (/api/search) with cadastral references ==="

echo -e "\n--- Testing search with a valid cadastral reference (AB123) ---"
curl -s -X POST -H "Content-Type: application/json" -d '{"query":"AB123"}' http://localhost:8000/api/search | format_json

echo -e "\n--- Testing search with a different valid cadastral reference (XY789) ---"
curl -s -X POST -H "Content-Type: application/json" -d '{"query":"XY789"}' http://localhost:8000/api/search | format_json

echo -e "\n=== Done testing ==="
