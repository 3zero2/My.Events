import {expect, test} from '@playwright/test';
import type {Attendee, Product} from '../../../frontend/src/types';
import {getAttendeeProductPrice} from '../../../frontend/src/utilites/products';

test('issued tickets include VAT and exclude fees', () => {
  const attendee = {product_price_id: 10} as Attendee;
  const product = {
    prices: [{id: 10, price: 1, tax_total: 0.05, fee_total: 0.07, price_including_taxes_and_fees: 1.12}],
  } as Product;

  expect(getAttendeeProductPrice(attendee, product)).toBe(1.05);
});

test('issued tickets use the attendee price tier and its tax total', () => {
  const attendee = {product_price_id: 11} as Attendee;
  const product = {
    prices: [
      {id: 10, price: 1, tax_total: 0.05},
      {id: 11, price: 10, tax_total: 0.75, fee_total: 2},
    ],
  } as Product;

  expect(getAttendeeProductPrice(attendee, product)).toBe(10.75);
});

for (const taxTotal of [0, undefined]) {
  test(`issued tickets without taxes retain the product price (tax_total: ${taxTotal})`, () => {
    const attendee = {product_price_id: 10} as Attendee;
    const product = {prices: [{id: 10, price: 1, tax_total: taxTotal, fee_total: 0.07}]} as Product;

    expect(getAttendeeProductPrice(attendee, product)).toBe(1);
  });
}

test('free tickets remain free', () => {
  const attendee = {product_price_id: 10} as Attendee;
  const product = {prices: [{id: 10, price: 0, tax_total: 0, fee_total: 0}]} as Product;

  expect(getAttendeeProductPrice(attendee, product)).toBe(0);
});

test('a missing price tier retains the zero fallback', () => {
  const attendee = {product_price_id: 11} as Attendee;
  const product = {prices: [{id: 10, price: 1, tax_total: 0.05}]} as Product;

  expect(getAttendeeProductPrice(attendee, product)).toBe(0);
});

test('missing product prices retain the zero fallback', () => {
  expect(getAttendeeProductPrice({product_price_id: 10} as Attendee, {} as Product)).toBe(0);
});
