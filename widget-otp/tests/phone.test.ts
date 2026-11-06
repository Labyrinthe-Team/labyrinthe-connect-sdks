import { describe, expect, it } from 'vitest';
import { PhoneNumber } from '@labyrinthe/connect';
import { format, formatDuration, resolveTexts, LOCALES } from '../src/i18n.js';
import { maskPhone, normalizePhone } from '../src/phone.js';
import { VERSION } from '../src/version.js';
import pkg from '../package.json' with { type: 'json' };

const SAMPLES = [
  '0812345678',
  '812345678',
  '243812345678',
  '+243812345678',
  '+243 81 234 5678',
  '00243812345678',
  '(081) 234-5678',
  '+33 6 12 34 56 78',
  '0033612345678',
  '0612345678',
  '12',
  '',
  '   ',
  'abc',
  '+1234567890123456',
  '2438123456789',
  // Spec 1.2.0 : sans « + », 11 chiffres ou plus ne commençant pas par 0 = déjà international.
  '33612345678',
  '447911123456',
  '14155550123',
  '242061234567',
  '24381234567',
  '123456789012345',
  '1234567890123456',
  '08123456789012',
];

describe('normalizePhone (spec 1.2.0)', () => {
  it('keeps foreign numbers without "+" and is idempotent', () => {
    expect(normalizePhone('33612345678')).toBe('33612345678');
    for (const sample of ['0812345678', '33612345678', '+243812345678']) {
      const once = normalizePhone(sample)!;
      expect(normalizePhone(once)).toBe(once);
      expect(normalizePhone(`+${once}`)).toBe(once);
    }
  });
});


describe('normalizePhone', () => {
  it.each(SAMPLES)('matches the Node SDK for %j', (sample) => {
    expect(normalizePhone(sample)).toBe(PhoneNumber.normalize(sample));
    expect(normalizePhone(sample, '33')).toBe(PhoneNumber.normalize(sample, '33'));
  });

  it('rejects an invalid default country code', () => {
    expect(normalizePhone('0812345678', '+243')).toBeNull();
  });

  it('masks numbers for display', () => {
    expect(maskPhone('243812345678')).toBe('+243 81•••••78');
    expect(maskPhone('33612345678')).toBe('+336 12••••78');
    expect(maskPhone('1234')).toBe('••••');
  });
});

describe('i18n', () => {
  it('fr by default, en on demand, every error code translated in both', () => {
    expect(resolveTexts(null).send).toBe('Recevoir le code');
    expect(resolveTexts('en-US').send).toBe('Send code');
    expect(Object.keys(LOCALES.en.errors).sort()).toEqual(Object.keys(LOCALES.fr.errors).sort());
    expect(Object.keys(LOCALES.en).sort()).toEqual(Object.keys(LOCALES.fr).sort());
  });

  it('merges overrides, including error texts', () => {
    const texts = resolveTexts('fr', { send: 'Go', errors: { expired: 'Trop tard' } });
    expect(texts.send).toBe('Go');
    expect(texts.errors.expired).toBe('Trop tard');
    expect(texts.errors.invalid_code).toBe('Code incorrect.');
  });

  it('formats placeholders and durations', () => {
    expect(format('{count} sur {total} {x}', { count: 1, total: 6 })).toBe('1 sur 6 {x}');
    expect(formatDuration(75)).toBe('1:15');
    expect(formatDuration(-3)).toBe('0:00');
  });
});

it('VERSION matches package.json', () => {
  expect(VERSION).toBe(pkg.version);
});
