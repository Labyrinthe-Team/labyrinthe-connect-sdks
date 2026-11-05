import { describe, expect, it } from 'vitest';
import { InvalidArgumentError, PhoneNumber } from '../src/index.js';

describe('PhoneNumber', () => {
  // Mêmes cas que le backend (App\Domain\Gateway\Support\PhoneNumber) et que le SDK PHP.
  it.each([
    ['international without plus', '243812345678', '243812345678'],
    ['plus', '+243812345678', '243812345678'],
    ['plus with spaces', '+243 81 234 5678', '243812345678'],
    ['double zero', '00243812345678', '243812345678'],
    ['local with trunk zero', '0812345678', '243812345678'],
    ['local without trunk zero', '812345678', '243812345678'],
    ['local with separators', '081-234-56.78', '243812345678'],
    ['other country with plus', '+33 6 12 34 56 78', '33612345678'],
    ['other country with 00', '0033612345678', '33612345678'],
    ['us number', '+1 415 555 2671', '14155552671'],
    ['empty', '', null],
    ['blank', '   ', null],
    ['letters', 'abc', null],
    ['too short', '+1234', null],
    ['too long', '+1234567890123456', null],
  ])('%s: %s → %s', (_label, input, expected) => {
    expect(PhoneNumber.normalize(input)).toBe(expected);
    expect(PhoneNumber.isValid(input)).toBe(expected !== null);
  });

  it('uses a configurable default country code', () => {
    expect(PhoneNumber.normalize('061234567', '242')).toBe('24261234567');
    expect(PhoneNumber.normalize('+243812345678', '242')).toBe('243812345678');
  });

  it('normalizeOrThrow', () => {
    expect(PhoneNumber.normalizeOrThrow('0812345678')).toBe('243812345678');
    expect(() => PhoneNumber.normalizeOrThrow('nope')).toThrow(InvalidArgumentError);
  });

  it('refuses invalid country codes', () => {
    expect(() => PhoneNumber.normalize('0812345678', '+243')).toThrow(InvalidArgumentError);
    expect(() => PhoneNumber.normalize('0812345678', '0')).toThrow(InvalidArgumentError);
  });

  it('masks numbers, keeping only the country prefix and the last digits', () => {
    expect(PhoneNumber.mask('243812345678')).toBe('24381*****78');
    expect(PhoneNumber.mask('+243 812 345 678')).toBe('24381*****78');
    expect(PhoneNumber.mask('33612345678')).toBe('3361*****78');
    expect(PhoneNumber.mask('1234')).toBe('****');
  });
});
