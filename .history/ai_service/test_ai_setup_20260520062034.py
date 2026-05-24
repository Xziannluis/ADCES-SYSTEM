#!/usr/bin/env python
"""
Test script to verify AI service virtual environment setup.
Run this after pip install completes.
"""

import sys
import importlib
from pathlib import Path


def test_import(package_name: str, display_name: str = None) -> bool:
    """Test if a package can be imported."""
    display = display_name or package_name
    try:
        importlib.import_module(package_name)
        print(f"✅ {display}")
        return True
    except ImportError as e:
        print(f"❌ {display} - {str(e)}")
        return False


def main():
    """Run all tests."""
    print("=" * 60)
    print("ADCES AI Service Setup Verification")
    print("=" * 60)
    print(f"Python: {sys.version}")
    print(f"Location: {Path(__file__).parent}")
    print()

    print("Checking Python version...")
    if sys.version_info >= (3, 8):
        print(f"✅ Python 3.8+ required (running 3.{sys.version_info.minor})")
    else:
        print(f"❌ Python 3.8+ required (running {sys.version_info.major}.{sys.version_info.minor})")
        return False

    print("\nChecking required packages...")
    packages = [
        ("fastapi", "FastAPI"),
        ("uvicorn", "Uvicorn"),
        ("pydantic", "Pydantic"),
        ("numpy", "NumPy"),
        ("pymysql", "PyMySQL"),
        ("sentence_transformers", "Sentence-Transformers"),
        ("torch", "PyTorch"),
        ("transformers", "Transformers"),
    ]

    all_ok = True
    for package, name in packages:
        if not test_import(package, name):
            all_ok = False

    print("\n" + "=" * 60)
    if all_ok:
        print("✅ All packages installed successfully!")
        print("\nYou can now run the AI service:")
        print("  uvicorn app:app --reload --host 127.0.0.1 --port 8000")
    else:
        print("❌ Some packages are missing!")
        print("\nTry running:")
        print("  pip install -r requirements.txt")
    print("=" * 60)

    return all_ok


if __name__ == "__main__":
    success = main()
    sys.exit(0 if success else 1)
