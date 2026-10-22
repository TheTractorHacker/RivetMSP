"""Persona task helper: counts the clicks a real user would make and records friction notes."""
import json, os, re
from ux_common import *

class Flow:
    def __init__(self, pg, persona, task):
        self.pg, self.persona, self.task = pg, persona, task
        self.clicks = 0; self.typed = 0; self.notes = []; self.steps = []; self.outcome = 'not-run'
    def click(self, target, note='', timeout=4000):
        loc = self.pg.locator(target).first if isinstance(target, str) else target
        try: loc.click(timeout=timeout)
        except Exception:
            self.pg.wait_for_timeout(1200); loc.click(timeout=timeout, force=True); self.steps.append('(needed force: control not stable/visible when first clicked)')
        self.clicks += 1; self.steps.append('click: ' + (note or str(target))[:70])
        self.pg.wait_for_timeout(500)
    def text(self, label):
        """click a nav-ish element by visible text"""
        self.click(self.pg.get_by_text(label, exact=True).first, label)
    def type(self, target, value, note=''):
        loc = self.pg.locator(target).first if isinstance(target, str) else target
        loc.fill(value); self.typed += 1; self.steps.append('type: ' + (note or str(target))[:50])
    def nav(self, *labels):
        last = self.pg.locator('.navbar-vertical').get_by_text(labels[-1], exact=True).first
        todo = labels[-1:] if last.is_visible() else labels
        for l in todo:
            self.pg.locator('.navbar-vertical').get_by_text(l, exact=True).first.click(timeout=4000)
            self.clicks += 1; self.steps.append('nav: ' + l); self.pg.wait_for_timeout(700)
    def note(self, t): self.notes.append(t)
    def done(self, outcome):
        self.outcome = outcome
        return dict(persona=self.persona, task=self.task, clicks=self.clicks, typed=self.typed, outcome=outcome, steps=self.steps, notes=self.notes, end=self.pg.url.replace(BASE, ''))

def shot(pg, name): pg.screenshot(path=f"{OUT}/persona_{name}.png", full_page=False)
