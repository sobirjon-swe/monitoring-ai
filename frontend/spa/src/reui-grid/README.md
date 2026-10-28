# ReUI Data Grid source

The files `data-grid.tsx`, `data-grid-table.tsx`, and `data-grid-i18n.tsx` are adapted from Keenthemes ReUI at commit [067aa0dc7f8bbfec1cd76fc5ad31f842be5f569a](https://github.com/keenthemes/reui/tree/067aa0dc7f8bbfec1cd76fc5ad31f842be5f569a/registry-reui/bases/base/reui/data-grid). Import paths are adapted to local modules. The local `compat.tsx` supplies browser-native stand-ins for upstream site primitives; the application enables only read-only table rendering. See LICENSE.md for the upstream MIT license.

The component uses local @tanstack/react-table 9.0.0. The installed dependency graph in package-lock.json includes @tanstack/react-store and @tanstack/store 0.11.2, @tanstack/table-core 9.0.0 and use-sync-external-store 1.7.0. Their package manifests identify MIT licenses. No ReUI example assets or remote runtime services are used.
