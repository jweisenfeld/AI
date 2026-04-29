#!/usr/bin/env python3
import unittest

from ingest import _pmc_extract_section_content, _pmc_table_to_text
from bs4 import BeautifulSoup


class TestPmcParser(unittest.TestCase):
    def test_table_to_text_serializes_caption_and_rows(self):
        html = """
        <table>
          <caption>Setbacks</caption>
          <tr><th>Zone</th><th>Front</th></tr>
          <tr><td>R-1</td><td>20 ft</td></tr>
        </table>
        """
        soup = BeautifulSoup(html, "html.parser")
        out = _pmc_table_to_text(soup.find("table"))
        self.assertIn("[TABLE]", out)
        self.assertIn("Table: Setbacks", out)
        self.assertIn("Zone | Front", out)
        self.assertIn("R-1 | 20 ft", out)

    def test_extract_section_content_removes_history_and_keeps_table_text(self):
        html = """
        <article class="type-Section" id="PMC_25.12.040">
          <header><h6><span class="num">25.12.040</span> <span class="name">Yard requirements</span></h6></header>
          <p>Main rule text.</p>
          <p class="note history">[Ord. 1234]</p>
          <table><tr><th>Use</th><th>Min</th></tr><tr><td>Residential</td><td>10</td></tr></table>
        </article>
        """
        soup = BeautifulSoup(html, "html.parser")
        out = _pmc_extract_section_content(soup.find("article"))
        self.assertIn("Main rule text.", out)
        self.assertIn("[TABLE]", out)
        self.assertIn("Use | Min", out)
        self.assertNotIn("Ord. 1234", out)


if __name__ == "__main__":
    unittest.main()
