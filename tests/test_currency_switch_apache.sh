#!/usr/bin/env bash
# Regression test for the "currency stuck after switching regions" bug.
# Runs against the local Apache production-sim at http://localhost:8182.
# Uses a SHARED cookie jar to preserve the PHP session across requests.
set -uo pipefail

BASE="http://localhost:8182"
JAR="$(mktemp)"
trap "rm -f $JAR" EXIT

extract_price() {
    # Extract the first product-price token (e.g. $209.99, CA$287.69, £..., €...)
    grep -oE '(C?A?\$|£|€)[0-9]+\.[0-9]{2}' "$1" | head -n 1
}

fetch() {
    local url="$1" out="$2"
    local code
    code=$(curl -s -L -b "$JAR" -c "$JAR" -o "$out" -w "%{http_code}" "$BASE$url")
    echo "$code"
}

PASS=0
FAIL=0
declare -a RESULTS

check() {
    local step="$1" want="$2" got="$3"
    if [[ "$got" == "$want" ]]; then
        RESULTS+=("PASS  $step  -> $got")
        PASS=$((PASS+1))
    else
        RESULTS+=("FAIL  $step  expected=$want  got=$got")
        FAIL=$((FAIL+1))
    fi
}

# Step (a): bare /shop.php => USD ($209.99)
T=$(mktemp); CODE=$(fetch "/shop.php" "$T"); P=$(extract_price "$T")
echo "(a) GET /shop.php           HTTP $CODE  first_price=$P"
check "(a) bare /shop.php" "\$209.99" "$P"

# Step (b): /ca/shop.php => CAD (CA$287.69)
T=$(mktemp); CODE=$(fetch "/ca/shop.php" "$T"); P=$(extract_price "$T")
echo "(b) GET /ca/shop.php        HTTP $CODE  first_price=$P"
check "(b) /ca/shop.php" "CA\$287.69" "$P"

# Step (c): bare /shop.php again => MUST be USD ($209.99), NOT CAD
T=$(mktemp); CODE=$(fetch "/shop.php" "$T"); P=$(extract_price "$T")
echo "(c) GET /shop.php (after CA) HTTP $CODE  first_price=$P"
check "(c) bare /shop.php after CA -> must reset to USD" "\$209.99" "$P"

# Step (d): /au/shop.php => AUD ('$' with different amount, ~A$319.x)
T=$(mktemp); CODE=$(fetch "/au/shop.php" "$T"); P=$(extract_price "$T")
echo "(d) GET /au/shop.php        HTTP $CODE  first_price=$P"
# AUD uses '$' but amount differs from USD - assert prefix is '$' AND amount != 209.99
if [[ "$P" =~ ^\$[0-9]+\.[0-9]{2}$ && "$P" != "\$209.99" ]]; then
    RESULTS+=("PASS  (d) /au/shop.php  -> $P (AUD-like, '$' prefix, amount != USD)")
    PASS=$((PASS+1))
else
    RESULTS+=("FAIL  (d) /au/shop.php  got=$P (expected AUD '$' with amount != 209.99)")
    FAIL=$((FAIL+1))
fi

# Step (e): bare /shop.php again => USD
T=$(mktemp); CODE=$(fetch "/shop.php" "$T"); P=$(extract_price "$T")
echo "(e) GET /shop.php (after AU) HTTP $CODE  first_price=$P"
check "(e) bare /shop.php after AU -> must reset to USD" "\$209.99" "$P"

# Step (f): /uk/shop.php => GBP (£...)
T=$(mktemp); CODE=$(fetch "/uk/shop.php" "$T"); P=$(extract_price "$T")
echo "(f) GET /uk/shop.php        HTTP $CODE  first_price=$P"
if [[ "$P" =~ ^£[0-9]+\.[0-9]{2}$ ]]; then
    RESULTS+=("PASS  (f) /uk/shop.php  -> $P (GBP)")
    PASS=$((PASS+1))
else
    RESULTS+=("FAIL  (f) /uk/shop.php  got=$P (expected £...)")
    FAIL=$((FAIL+1))
fi

# Step (g): /eu/shop.php => EUR (€...)
T=$(mktemp); CODE=$(fetch "/eu/shop.php" "$T"); P=$(extract_price "$T")
echo "(g) GET /eu/shop.php        HTTP $CODE  first_price=$P"
if [[ "$P" =~ ^€[0-9]+\.[0-9]{2}$ ]]; then
    RESULTS+=("PASS  (g) /eu/shop.php  -> $P (EUR)")
    PASS=$((PASS+1))
else
    RESULTS+=("FAIL  (g) /eu/shop.php  got=$P (expected €...)")
    FAIL=$((FAIL+1))
fi

# Step (h): bare /shop.php => USD final
T=$(mktemp); CODE=$(fetch "/shop.php" "$T"); P=$(extract_price "$T")
echo "(h) GET /shop.php (final)   HTTP $CODE  first_price=$P"
check "(h) bare /shop.php final -> USD" "\$209.99" "$P"

echo ""
echo "============================================================"
echo "RESULTS  pass=$PASS  fail=$FAIL"
for line in "${RESULTS[@]}"; do echo "  $line"; done
echo "============================================================"
[[ $FAIL -eq 0 ]] && exit 0 || exit 1
