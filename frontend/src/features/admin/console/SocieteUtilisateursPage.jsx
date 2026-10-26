import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link2, List, ShieldCheck, X } from 'lucide-react'
import Select from '../../../components/ui/Select'
import { Actions, BoutonModifier, BoutonSupprimer, TableConsole, flash, messageApi } from './kit'
import { BoutonsFormulaire, CarteGrille, GrilleConsole } from './grille'
import {
  detacherSociete, detacherToutesSocietes, fetchLiaisonsSocietes, fetchReferentielsSocietes, synchroniserSocietes,
} from './rattachementsApi'

const VIDE = { utilisateurId: '', societes: [], admins: [] }

/**
 * Société - Utilisateurs — l'écran parcauto affectations-societe-user : à gauche le
 * formulaire (un compte, ses sociétés), à droite la liste regroupée par compte.
 *
 * Choisir un compte coche les sociétés auxquelles il est déjà rattaché ; « Enregistrer »
 * fait de la liste cochée SA liste — une société décochée est détachée. La case
 * « Admin » d'une société cochée fait du compte l'administrateur de cette société.
 *
 * Super Admin seul : ces rattachements valent pour toute la suite d'applications, et un
 * compte sans société ne peut pas se connecter.
 */
export default function SocieteUtilisateursPage() {
  const qc = useQueryClient()
  const [form, setForm] = useState(VIDE)
  const [erreur, setErreur] = useState(null)

  const { data: referentiels } = useQuery({ queryKey: ['rattachements-referentiels'], queryFn: fetchReferentielsSocietes })
  const { data: liaisons, isLoading, error } = useQuery({ queryKey: ['rattachements-societes'], queryFn: fetchLiaisonsSocietes })

  const societes = useMemo(() => referentiels?.societes ?? [], [referentiels])

  // Une ligne par compte, comme parcauto : tous les comptes actifs, plus ceux — désactivés
  // — qui garderaient encore un rattachement (on doit pouvoir le leur retirer).
  const comptes = useMemo(() => {
    const parId = new Map()
    for (const u of referentiels?.utilisateurs ?? []) parId.set(u.id, { ...u, liaisons: [] })
    for (const l of liaisons?.affectations ?? []) {
      if (!parId.has(l.utilisateur_id)) {
        parId.set(l.utilisateur_id, {
          id: l.utilisateur_id, login: l.login ?? `#${l.utilisateur_id}`, nom: l.utilisateur, liaisons: [], horsListe: true,
        })
      }
      parId.get(l.utilisateur_id).liaisons.push(l)
    }
    return [...parId.values()]
  }, [referentiels, liaisons])

  const invalider = () => qc.invalidateQueries({ queryKey: ['rattachements-societes'] })

  /** Charge un compte dans le formulaire, ses sociétés et ses statuts d'admin cochés. */
  const charger = (id) => {
    const compte = comptes.find((c) => String(c.id) === String(id))
    setErreur(null)
    setForm({
      utilisateurId: id ? String(id) : '',
      societes: compte?.liaisons.map((l) => l.societe_code) ?? [],
      admins: compte?.liaisons.filter((l) => l.admin_societe).map((l) => l.societe_code) ?? [],
    })
  }

  const enregistrer = useMutation({
    mutationFn: synchroniserSocietes,
    onSuccess: () => {
      invalider()
      const compte = comptes.find((c) => String(c.id) === form.utilisateurId)
      flash.succes(`Les sociétés de ${compte?.login ?? 'l’utilisateur'} ont été enregistrées.`)
      setForm(VIDE)
    },
    onError: (e) => setErreur(messageApi(e)),
  })

  const detacher = useMutation({
    mutationFn: (l) => detacherSociete(l.id),
    onSuccess: (_, l) => { invalider(); flash.succes(`${l.societe_nom ?? l.societe_code} a été retirée de ${l.login ?? 'l’utilisateur'}.`) },
    onError: (e) => flash.erreur(messageApi(e)),
  })

  const retirerTout = useMutation({
    mutationFn: (c) => detacherToutesSocietes(c.id),
    onSuccess: (_, c) => {
      invalider()
      if (form.utilisateurId === String(c.id)) setForm(VIDE)
      flash.succes(`Toutes les sociétés de ${c.login} ont été retirées.`)
    },
    onError: (e) => flash.erreur(messageApi(e)),
  })

  const cocher = (code, coche) => setForm((f) => ({
    ...f,
    societes: coche ? [...f.societes, code] : f.societes.filter((c) => c !== code),
    admins: coche ? f.admins : f.admins.filter((c) => c !== code),
  }))
  const cocherAdmin = (code, coche) => setForm((f) => ({
    ...f, admins: coche ? [...f.admins, code] : f.admins.filter((c) => c !== code),
  }))

  const soumettre = () => {
    const compte = comptes.find((c) => String(c.id) === form.utilisateurId)
    if (!compte) return
    if (form.societes.length === 0 && compte.liaisons.length > 0
      && !window.confirm(`Aucune société cochée : retirer toutes les sociétés de ${compte.login} ?`)) return
    enregistrer.mutate(form)
  }

  const colonnes = [
    { cle: 'login', titre: 'Login', className: 'font-semibold' },
    { cle: 'nom', titre: 'Nom & Prénoms' },
    {
      cle: 'societes', titre: 'Sociétés', className: 'text-left',
      valeur: (c) => c.liaisons.map((l) => l.societe_nom ?? l.societe_code).join(', '),
      rendu: (c) => (c.liaisons.length === 0 ? <span className="text-muted">Aucune</span> : (
        <div className="flex flex-wrap gap-1">
          {c.liaisons.map((l) => (
            <span
              key={l.id}
              className="inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-semibold"
              style={{ background: 'var(--info-bg)', color: 'var(--info-text)' }}
              title={l.admin_societe ? 'Administrateur de cette société' : undefined}
            >
              {l.admin_societe && <ShieldCheck size={12} aria-label="Administrateur" />}
              {l.societe_nom ?? l.societe_code}
              <button
                type="button" title="Retirer" aria-label={`Retirer ${l.societe_nom ?? l.societe_code}`}
                disabled={detacher.isPending}
                onClick={() => { if (window.confirm(`Retirer ${l.societe_nom ?? l.societe_code} de cet utilisateur ?`)) detacher.mutate(l) }}
                className="ml-0.5 opacity-70 hover:opacity-100" style={{ color: 'var(--danger-text)' }}
              >
                <X size={11} />
              </button>
            </span>
          ))}
        </div>
      )),
    },
    {
      cle: 'actions', titre: 'Actions', triable: false, className: 'w-28',
      rendu: (c) => (
        <Actions>
          {!c.horsListe && (
            <BoutonModifier titre="Modifier" onClick={() => { charger(c.id); window.scrollTo({ top: 0, behavior: 'smooth' }) }} />
          )}
          <BoutonSupprimer
            titre="Retirer tout" disabled={c.liaisons.length === 0 || retirerTout.isPending}
            confirmation={`Retirer toutes les sociétés de ${c.login} ?`}
            onConfirme={() => retirerTout.mutate(c)}
          />
        </Actions>
      ),
    },
  ]

  return (
    <GrilleConsole
      gauche={(
        <CarteGrille icon={Link2} titre="Affecter Sociétés à un Utilisateur">
          <form onSubmit={(e) => { e.preventDefault(); soumettre() }} noValidate className="space-y-4">
            <Select label="Utilisateur" value={form.utilisateurId} onChange={(e) => charger(e.target.value)}>
              <option value="">-- Choisir un utilisateur --</option>
              {(referentiels?.utilisateurs ?? []).map((u) => (
                <option key={u.id} value={u.id}>{u.login}{u.nom ? ` - ${u.nom}` : ''}</option>
              ))}
            </Select>

            <div>
              <p className="mb-1.5 block text-sm font-bold text-heading">Sociétés</p>
              <div className="max-h-72 space-y-0.5 overflow-y-auto rounded-xl border p-2" style={{ borderColor: 'var(--border)' }}>
                {societes.length === 0 && <p className="px-2 py-3 text-sm text-muted">Aucune société.</p>}
                {societes.map((s) => {
                  const coche = form.societes.includes(s.code)
                  return (
                    <div key={s.code} className="flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 hover:bg-[var(--surface-2)]">
                      <label className="flex flex-1 cursor-pointer items-center gap-2 text-sm">
                        <input
                          type="checkbox" checked={coche} disabled={!form.utilisateurId}
                          onChange={(e) => cocher(s.code, e.target.checked)}
                        />
                        <span><span className="font-semibold">{s.code}</span> - {s.nom}</span>
                      </label>
                      {coche && (
                        <label className="flex cursor-pointer items-center gap-1 text-xs font-semibold text-muted" title="Administrateur de cette société">
                          <input
                            type="checkbox" checked={form.admins.includes(s.code)}
                            onChange={(e) => cocherAdmin(s.code, e.target.checked)}
                          />
                          Admin
                        </label>
                      )}
                    </div>
                  )
                })}
              </div>
              <p className="mt-1.5 text-xs text-muted">
                Lorsque vous choisissez un utilisateur, les sociétés déjà affectées sont
                automatiquement cochées ; une société décochée lui est retirée à l’enregistrement.
                « Admin » fait de lui l’administrateur de la société.
              </p>
            </div>

            {erreur && <p className="text-sm" style={{ color: 'var(--danger-text)' }}>{erreur}</p>}

            <BoutonsFormulaire
              onReinitialiser={() => { setForm(VIDE); setErreur(null) }}
              enCours={enregistrer.isPending}
              desactive={!form.utilisateurId}
            />
          </form>
        </CarteGrille>
      )}
      droite={(
        <CarteGrille icon={List} titre="Liste des Affectations Utilisateurs">
          <TableConsole
            colonnes={colonnes}
            lignes={comptes}
            chargement={isLoading}
            erreur={error}
            vide="Aucun utilisateur."
          />
        </CarteGrille>
      )}
    />
  )
}
