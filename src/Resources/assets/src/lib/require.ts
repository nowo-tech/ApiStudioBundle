/**
 * The closed `require()` library allow-list of sandboxed and server-side scripts (spec 003 FR-SCR-007, 1.15). Bundled
 * into the sandbox frame and the server runner only (never into the console page); anything else throws. The list and
 * the versions are recorded in the CHANGELOG.
 *
 * @packageDocumentation
 */
import Ajv from 'ajv';
import CryptoJS from 'crypto-js';
import dayjs from 'dayjs';
import { XMLBuilder, XMLParser, XMLValidator } from 'fast-xml-parser';
import _ from 'lodash';
import { NIL, v4, v7, validate, version } from 'uuid';
import { expect } from './expect';

const LIBRARIES: Readonly<Record<string, unknown>> = Object.freeze({
  ajv: Ajv,
  chai: Object.freeze({ expect }),
  'crypto-js': CryptoJS,
  dayjs,
  'fast-xml-parser': Object.freeze({ XMLParser, XMLBuilder, XMLValidator }),
  lodash: _,
  // Postman scripts written for moment: dayjs covers the common formatting / arithmetic API.
  moment: dayjs,
  uuid: Object.freeze({ v4, v7, validate, version, NIL }),
});

/** Names `require()` accepts. */
export const ALLOWED_LIBRARIES: readonly string[] = Object.freeze(Object.keys(LIBRARIES));

/** `require()` of sandboxed / server-side scripts. */
export function scriptRequire(name: unknown): unknown {
  if (typeof name !== 'string' || !Object.prototype.hasOwnProperty.call(LIBRARIES, name)) {
    throw new Error(`require('${String(name)}') is not available. Allowed: ${ALLOWED_LIBRARIES.join(', ')}.`);
  }

  return LIBRARIES[name];
}
