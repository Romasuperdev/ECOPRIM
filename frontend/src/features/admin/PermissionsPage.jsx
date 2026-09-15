import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ShieldCheck } from 'lucide-react'
import Select from '../../components/ui/Select'
import Button from '../../components/ui/Button'
import { enregistrerPermissionsRole, fetchPermissionsRole, fetchRoles } from './adminApi'

/**
 * L'éditeur d'un rôle précis, remonté (voir `key={roleId}` plus bas) à chaque changement
 * de rôle : `coches` s'initialise alors directement depuis `permissions`, sans effet pour
 * le resynchroniser après coup.
 */
function EditeurPermissions({ roleId, roleActuel, permissions, onEnregistre }) {
  const [coches, setCoches] = useState(() => new Set(permissions.accordees))

  const enregistrer = useMutation({
    mutationFn: () => enregistrerPermissionsRole(roleId, Array.from(coches)),
    onSuccess: onEnregistre,
  })

  const basculer = (code) => {
    setCoches((prev) => {
      const suivant = new Set(prev)
      if (suivant.has(code)) {
        suivant.delete(code)
      } else {
        suivant.add(code)
      }
      return suivant
    })
  }

  const parGroupe = Object.entries(permissions.catalogue).reduce((acc, [code, info]) => {
    (acc[info.groupe] ??= []).push({ code, ...info })
    return acc
  }, {})

  return (
    <div className="space-y-6">
      {Object.entries(parGroupe).map(([groupe, items]) => (
        <div key={groupe} className="overflow-hidden rounded-xl border border-slate-200 bg-white">
          <div className="border-b border-slate-200 bg-slate-50 px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-slate-500">
            {groupe}
          </div>
          <div className="divide-y divide-slate-100">
            {items.map(({ code, libelle }) => (
              <label key={code} className="flex cursor-pointer items-center gap-3 px-4 py-2.5 text-sm hover:bg-slate-50">
                <input
                  type="checkbox"
                  checked={coches.has(code)}
                  onChange={() => basculer(code)}
                  className="size-4 rounded border-slate-300 text-primary-600 focus:ring-primary-400"
                />
                <span className="text-slate-700">{libelle}</span>
              </label>
            ))}
          </div>
        </div>
      ))}

      <div className="flex items-center justify-between">
        <p className="text-xs text-slate-400">
          <ShieldCheck size={13} className="mr-1 inline" />
          {roleActuel?.nom ?? 'Ce rôle'} — {coches.size} permission{coches.size > 1 ? 's' : ''} accordée{coches.size > 1 ? 's' : ''}
        </p>
        <Button onClick={() => enregistrer.mutate()} disabled={enregistrer.isPending}>
          {enregistrer.isPending ? 'Enregistrement…' : 'Enregistrer'}
        </Button>
      </div>
    </div>
  )
}

/**
 * Ce que chaque rôle a le droit de faire, appliqué réellement côté serveur (pas un simple
 * affichage) : décocher une permission bloque vraiment l'action pour ce rôle.
 *
 * Un Super Admin, un Admin Société et un Admin Établissement gardent toujours accès à
 * tout, quelles que soient les cases cochées ici — cette page ne règle que les rôles
 * « métier » (Enseignant, Secrétaire, Comptable...).
 */
export default function PermissionsPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const roleId = searchParams.get('role') ?? ''
  const qc = useQueryClient()

  const { data: roles } = useQuery({ queryKey: ['roles'], queryFn: fetchRoles })
  const { data: permissions, isLoading } = useQuery({
    queryKey: ['role-permissions', roleId],
    queryFn: () => fetchPermissionsRole(roleId),
    enabled: Boolean(roleId),
  })

  const roleActuel = roles?.find((r) => String(r.id) === String(roleId))

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-800">Permissions</h1>
        <p className="mt-1 text-sm text-slate-500">
          Ce que chaque rôle a le droit de faire. Les administrateurs (Super Admin, Admin Société,
          Admin Établissement) gardent toujours accès à tout — ces cases ne concernent que les rôles métier.
        </p>
      </div>

      <div className="mb-6 max-w-sm">
        <Select
          label="Rôle"
          value={roleId}
          onChange={(e) => setSearchParams(e.target.value ? { role: e.target.value } : {})}
        >
          <option value="">— Choisir un rôle —</option>
          {roles?.map((r) => (
            <option key={r.id} value={r.id}>{r.nom}{r.societe_code ? '' : ' (général)'}</option>
          ))}
        </Select>
      </div>

      {!roleId && (
        <p className="rounded-xl border border-dashed border-slate-300 px-4 py-10 text-center text-sm text-slate-400">
          Choisissez un rôle pour voir et modifier ses permissions.
        </p>
      )}

      {roleId && isLoading && <p className="text-sm text-slate-400">Chargement…</p>}

      {roleId && permissions && (
        <EditeurPermissions
          key={roleId}
          roleId={roleId}
          roleActuel={roleActuel}
          permissions={permissions}
          onEnregistre={(data) => qc.setQueryData(['role-permissions', roleId], data)}
        />
      )}
    </div>
  )
}
