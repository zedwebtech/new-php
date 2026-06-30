#!/usr/bin/env python3
"""
Backend testing for PHP software store - Product page install blocks
Tests the product.php endpoint to verify install block rendering
"""

import requests
from bs4 import BeautifulSoup
import sys

BASE_URL = "https://robot-rules.preview.emergentagent.com"

def test_product_page_install_block(slug, expected_buttons):
    """
    Test a product page for the install block and verify button hrefs
    
    Args:
        slug: Product slug
        expected_buttons: Dict with keys 'download', 'guide', 'activate' (or None if button should be absent)
    
    Returns:
        tuple: (success: bool, message: str)
    """
    url = f"{BASE_URL}/product.php?slug={slug}"
    
    try:
        response = requests.get(url, timeout=10)
        if response.status_code != 200:
            return False, f"HTTP {response.status_code}"
        
        soup = BeautifulSoup(response.text, 'html.parser')
        
        # Find the install block
        install_block = soup.find(attrs={"data-testid": "product-install-block"})
        
        # If expected_buttons is None, block should be absent
        if expected_buttons is None:
            if install_block:
                return False, "Install block present but should be ABSENT"
            return True, "Install block correctly absent"
        
        # Block should be present
        if not install_block:
            return False, "Install block NOT FOUND (data-testid='product-install-block')"
        
        # Check download button
        if 'download' in expected_buttons:
            if expected_buttons['download'] is None:
                # Button should be absent
                download_btn = install_block.find(attrs={"data-testid": "install-download-btn"})
                if download_btn:
                    return False, f"Download button present but should be ABSENT"
            else:
                download_btn = install_block.find(attrs={"data-testid": "install-download-btn"})
                if not download_btn:
                    return False, "Download button NOT FOUND (data-testid='install-download-btn')"
                href = download_btn.get('href', '')
                if expected_buttons['download'] not in href:
                    return False, f"Download href mismatch: expected '{expected_buttons['download']}' in '{href}'"
        
        # Check guide button
        if 'guide' in expected_buttons and expected_buttons['guide']:
            guide_btn = install_block.find(attrs={"data-testid": "install-guide-btn"})
            if not guide_btn:
                return False, "Guide button NOT FOUND (data-testid='install-guide-btn')"
            href = guide_btn.get('href', '')
            if expected_buttons['guide'] not in href:
                return False, f"Guide href mismatch: expected '{expected_buttons['guide']}' in '{href}'"
        
        # Check activate button
        if 'activate' in expected_buttons and expected_buttons['activate']:
            activate_btn = install_block.find(attrs={"data-testid": "install-activate-btn"})
            if not activate_btn:
                return False, "Activate button NOT FOUND (data-testid='install-activate-btn')"
            href = activate_btn.get('href', '')
            if expected_buttons['activate'] not in href:
                return False, f"Activate href mismatch: expected '{expected_buttons['activate']}' in '{href}'"
        
        return True, "All buttons present with correct hrefs"
        
    except Exception as e:
        return False, f"Exception: {str(e)}"


def test_link_liveness(url, description):
    """
    Test if a URL returns HTTP 200/206/3xx (not 404)
    For .exe files, use range request to avoid downloading entire file
    """
    try:
        headers = {}
        if url.endswith('.exe'):
            headers['Range'] = 'bytes=0-0'
        
        response = requests.get(url, timeout=10, allow_redirects=True, headers=headers)
        
        if response.status_code in [200, 206, 301, 302, 303, 307, 308]:
            return True, f"HTTP {response.status_code}"
        else:
            return False, f"HTTP {response.status_code}"
    except Exception as e:
        return False, f"Exception: {str(e)}"


def test_order_success_page(order_number):
    """
    Test order-success page for install/download/activate buttons
    
    Args:
        order_number: Order number to test
    
    Returns:
        tuple: (success: bool, message: str, details: dict)
    """
    url = f"{BASE_URL}/order-success.php?order={order_number}"
    
    try:
        response = requests.get(url, timeout=10)
        if response.status_code != 200:
            return False, f"HTTP {response.status_code}", {}
        
        soup = BeautifulSoup(response.text, 'html.parser')
        
        # Find all the success buttons
        installer_btns = soup.find_all(attrs={"data-testid": "success-installer-btn"})
        activate_btns = soup.find_all(attrs={"data-testid": "success-activate-btn"})
        guide_btns = soup.find_all(attrs={"data-testid": "success-installguide-btn"})
        guide_installer_btns = soup.find_all(attrs={"data-testid": "guide-installer-btn"})
        
        details = {
            'installer_count': len(installer_btns),
            'activate_count': len(activate_btns),
            'guide_count': len(guide_btns),
            'guide_installer_count': len(guide_installer_btns),
            'installer_hrefs': [btn.get('href', '') for btn in installer_btns],
            'activate_hrefs': [btn.get('href', '') for btn in activate_btns],
            'guide_hrefs': [btn.get('href', '') for btn in guide_btns],
            'guide_installer_hrefs': [btn.get('href', '') for btn in guide_installer_btns]
        }
        
        # Check if we have at least some buttons
        total_buttons = (len(installer_btns) + len(activate_btns) + 
                        len(guide_btns) + len(guide_installer_btns))
        
        if total_buttons == 0:
            return False, "No install/download/activate buttons found", details
        
        return True, f"Found {total_buttons} buttons total", details
        
    except Exception as e:
        return False, f"Exception: {str(e)}", {}


def main():
    print("=" * 80)
    print("BACKEND TESTING: PHP Software Store - Product Install Blocks")
    print("=" * 80)
    print()
    
    all_passed = True
    
    # Test 1: microsoft-office-2024-professional-plus-windows (3 buttons)
    print("TEST 1: microsoft-office-2024-professional-plus-windows")
    print("-" * 80)
    success, msg = test_product_page_install_block(
        'microsoft-office-2024-professional-plus-windows',
        {
            'download': 'Office_2024_EN_64Bits.exe',
            'guide': 'manuals.winandoffice.com/o24pp/',
            'activate': 'setup.office.com'
        }
    )
    print(f"Result: {'✅ PASS' if success else '❌ FAIL'} - {msg}")
    all_passed = all_passed and success
    print()
    
    # Test 2: windows-11-pro (3 buttons)
    print("TEST 2: windows-11-pro")
    print("-" * 80)
    success, msg = test_product_page_install_block(
        'windows-11-pro',
        {
            'download': 'MediaCreationTool.exe',
            'guide': 'manuals.winandoffice.com/w11p/',
            'activate': 'account.microsoft.com/account'
        }
    )
    print(f"Result: {'✅ PASS' if success else '❌ FAIL'} - {msg}")
    all_passed = all_passed and success
    print()
    
    # Test 3: microsoft-project-2024-professional-pc (3 buttons)
    print("TEST 3: microsoft-project-2024-professional-pc")
    print("-" * 80)
    success, msg = test_product_page_install_block(
        'microsoft-project-2024-professional-pc',
        {
            'download': 'project_2024_EN_64Bits.exe',
            'guide': 'manuals.winandoffice.com/p24p/',
            'activate': 'setup.office.com'
        }
    )
    print(f"Result: {'✅ PASS' if success else '❌ FAIL'} - {msg}")
    all_passed = all_passed and success
    print()
    
    # Test 4: microsoft-visio-2021-professional-windows-pc (3 buttons)
    print("TEST 4: microsoft-visio-2021-professional-windows-pc")
    print("-" * 80)
    success, msg = test_product_page_install_block(
        'microsoft-visio-2021-professional-windows-pc',
        {
            'download': 'visio_2021_EN_pro_64Bits.exe',
            'guide': 'manuals.winandoffice.com/v21p/',
            'activate': 'setup.office.com'
        }
    )
    print(f"Result: {'✅ PASS' if success else '❌ FAIL'} - {msg}")
    all_passed = all_passed and success
    print()
    
    # Test 5: microsoft-office-home-business-2024-mac (2 buttons, NO download)
    print("TEST 5: microsoft-office-home-business-2024-mac")
    print("-" * 80)
    success, msg = test_product_page_install_block(
        'microsoft-office-home-business-2024-mac',
        {
            'download': None,  # Should be ABSENT
            'guide': 'manuals.winandoffice.com/o21hbmac/',
            'activate': 'setup.office.com'
        }
    )
    print(f"Result: {'✅ PASS' if success else '❌ FAIL'} - {msg}")
    all_passed = all_passed and success
    print()
    
    # Test 6: bitdefender-antivirus-for-mac-1-mac-1-year (block should be absent)
    print("TEST 6: bitdefender-antivirus-for-mac-1-mac-1-year")
    print("-" * 80)
    success, msg = test_product_page_install_block(
        'bitdefender-antivirus-for-mac-1-mac-1-year',
        None  # Block should be ABSENT
    )
    print(f"Result: {'✅ PASS' if success else '❌ FAIL'} - {msg}")
    all_passed = all_passed and success
    print()
    
    # Test 7: Link liveness checks
    print("TEST 7: Link Liveness Checks")
    print("-" * 80)
    
    links_to_check = [
        ('https://download.winandoffice.com/Volume/office/2024/EN/Office_2024_EN_64Bits.exe', 'Office 2024 installer'),
        ('https://manuals.winandoffice.com/o24pp/', 'Office 2024 PP manual'),
        ('https://setup.office.com', 'Office setup page')
    ]
    
    for url, desc in links_to_check:
        success, msg = test_link_liveness(url, desc)
        print(f"  {desc}: {'✅ PASS' if success else '❌ FAIL'} - {msg}")
        all_passed = all_passed and success
    
    print()
    
    # Test 8: Order-success page buttons
    print("TEST 8: Order-success page (MVT-DEMO-002)")
    print("-" * 80)
    success, msg, details = test_order_success_page('MVT-DEMO-002')
    print(f"Result: {'✅ PASS' if success else '❌ FAIL'} - {msg}")
    if details:
        print(f"  Details:")
        print(f"    - success-installer-btn: {details['installer_count']} found")
        if details['installer_hrefs']:
            for href in details['installer_hrefs']:
                print(f"      → {href}")
        print(f"    - success-activate-btn: {details['activate_count']} found")
        if details['activate_hrefs']:
            for href in details['activate_hrefs']:
                print(f"      → {href}")
        print(f"    - success-installguide-btn: {details['guide_count']} found")
        if details['guide_hrefs']:
            for href in details['guide_hrefs']:
                print(f"      → {href}")
        print(f"    - guide-installer-btn: {details['guide_installer_count']} found")
        if details['guide_installer_hrefs']:
            for href in details['guide_installer_hrefs']:
                print(f"      → {href}")
    all_passed = all_passed and success
    print()
    
    # Summary
    print("=" * 80)
    print(f"OVERALL: {'✅ ALL TESTS PASSED' if all_passed else '❌ SOME TESTS FAILED'}")
    print("=" * 80)
    
    return 0 if all_passed else 1


if __name__ == "__main__":
    sys.exit(main())
