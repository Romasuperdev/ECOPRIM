import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link2, List } from 'lucide-react'
import Select from '../../../components/ui/Select'
import SelecteurEleves from '../SelecteurEleves'
import { createAffectation, fetchRoles, fetchTousEtablissements, fetchTousUtilisateurs, terminerAffectation } from '../adminApi'
import { fetchConsoleContexte } from '../consoleContexteApi'
import { Actions, BoutonSupprimer, TableConsole, flash, messageApi } from './kit'
import { BoutonsFormulaire, CarteGrille, GrilleConsole } from './grille'
import { fetchAffectationsEtablissement } from './rattachementsApi'

const VIDE = { societe: '', etablissement: '', utilisateur: '', role: '', eleves: [] }

/**
 * Utilisateur - Établissements — l'écran parcauto console/affectations-user-etab : à
 * gauche le formulaire (Société → Établissement de cette société, Utilisateur, et le
 * RÔLE à la place de l'application / superviseur de parcauto), à droite la liste.
 *
 * Les règles ne sont pas recopiées ici : elles vivent dans l'API (AffectationController)
 * — une seule société par compte, pas de doublon, pas de rôle Admin Société conféré par
 * un Admin Établissement — et s'appliquent qu'on passe par ici ou par la fiche du compte.
 */
export default function UtilisateurEtablissementsPage() {
  const qc = useQueryClient()
  const [form, setForm] = useState(VIDE)
  const [erreur, setErreur] = useState(null)

  const { data: affectations, isLoading, error: erreurListe } = useQuery({
    queryKey: ['affectations-console'], queryFn: fetchAffectationsEtablissement, retry: false,
  })
  const { data: contexte } = useQuery({ queryKey: ['console-contexte'], queryFn: fetchConsoleContexte, retry: false })
  const { data: etablissements } = useQuery({ queryKey: ['etablissements', 'tous'], queryFn: () => fetchTousEtablissements() })
  const { data: utilisateurs } = useQuery({ queryKey: ['utilisateurs', 'tous'], queryFn: fetchTousUtilisateurs })
  const { data: roles } = useQuery({ queryKey: ['roles'], queryFn: fetchRoles })

  // Les sociétés du périmètre ; un administrateur d'une seule société l'a d'office.
  const societes = useMemo(() => contexte?.societes ?? [], [contexte])
  const societe = form.societe || (societes.length === 1 ? societes[0].code : '')

  // Seuls les établissements gérés dans ECOPRIM et actifs reçoivent une affectation.
  const etabsSociete = (etablissements ?? []).filter((e) => e.repris && e.actif && (!societe || e.societe_code === societe))
  const estRoleParent = roles?.find((r) => String(r.id) === String(form.role))?.code === 'parent'

  const champ = (k, v) => setForm((f) => ({ ...f, [k]: v }))

  const affecter = useMutation({
    mutationFn: () => createAffectation({
      rh_user_id: Number(form.utilisateur), etablissement_code: form.etablissement, role_id: Number(form.role),
      eleves: estRoleParent ? form.eleves.map((e) => e.matricule) : undefined,
    }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['affectations-console'] })
      qc.invalidateQueries({ queryKey: ['utilisateurs'] })
      flash.succes('L’affectation a été enregistrée.')
      setForm((f) => ({ ...VIDE, societe: f.societe }))
      setErreur(null)
    },
    onError: (e) => setErreur(messageApi(e)),
  })

  const supprimer = useMutation({
    mutationFn: (a) => terminerAffectation(a.id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['affectations-console'] })
      qc.invalidateQueries({ queryKey: ['utilisateurs'] })
      flash.succes('L’affectation a été supprimée.')
    },
    onError: (e) => flash.erreur(messageApi(e)),
  })

  const colonnes = [
    {
      cle: 'utilisateur', titre: 'Utilisateur', valeur: (a) => `${a.login ?? ''} ${a.utilisateur ?? ''}`,
      rendu: (a) => (
        <>
          {/* La fiche du compte garde l'historique complet de ses affectations. */}
          <Link to={`/admin/utilisateurs/${a.rh_user_id}`} className="font-semibold hover:underline" style={{ color: 'var(--brand-accent)' }}>
            {a.login ?? `#${a.rh_user_id}`}
          </Link>
          {a.utilisateur && a.utilisateur !== a.login && <div className="text-xs text-muted">{a.utilisateur}</div>}
        </>
      ),
    },
    {
      cle: 'etablissement', titre: 'Établissement', valeur: (a) => a.etablissement ?? a.etablissement_code,
      rendu: (a) => (
        <>
          {a.etablissement ?? a.etablissement_code}
          {a.etablissement && <div className="text-xs text-muted">{a.etablissement_code}</div>}
        </>
      ),
    },
    { cle: 'societe_code', titre: 'Société' },
    {
      cle: 'role', titre: 'Rôle',
      rendu: (a) => (
        <>
          <span className="rounded-full px-3 py-1 text-xs font-semibold" style={{ background: 'var(--info-bg)', color: 'var(--info-text)' }}>
            {a.role ?? '—'}
          </span>
          {/* Une affectation désactivée ou échue reste en base mais n'ouvre plus rien. */}
          {(!a.actif || a.echue) && (
            <div className="mt-1 text-xs font-semibold" style={{ color: 'var(--warning-text)' }}>
              {!a.actif ? 'Désactivée' : 'Échue'}
            </div>
          )}
        </>
      ),
    },
    {
      cle: 'actions', titre: 'Actions', triable: false,
      rendu: (a) => (
        <Actions>
          <BoutonSupprimer
            disabled={supprimer.isPending}
            confirmation={`Supprimer l’affectation de ${a.login ?? 'ce compte'} à ${a.etablissement ?? a.etablissement_code} (${a.role ?? 'rôle'}) ?`}
            onConfirme={() => supprimer.mutate(a)}
          />
        </Actions>
      ),
    },
  ]

  const incomplet = !form.etablissement || !form.utilisateur || !form.role

  return (
    <GrilleConsole
      gauche={(
        <CarteGrille icon={Link2} titre="Affecter un Utilisateur à un Établissement">
          <form onSubmit={(e) => { e.preventDefault(); if (!incomplet) affecter.mutate() }} noValidate className="space-y-4">
            <Select
              label="Société *" value={societe}
              onChange={(e) => setForm((f) => ({ ...f, societe: e.target.value, etablissement: '' }))}
            >
              <option value="">-- Choisir une société --</option>
              {societes.map((s) => <option key={s.code} value={s.code}>{s.code} - {s.nom}</option>)}
            </Select>
            <Select
              label="Établissement *" value={form.etablissement} disabled={!societe}
              onChange={(e) => champ('etablissement', e.target.value)}
            >
              <option value="">-- Choisir un établissement --</option>
              {etabsSociete.map((e) => <option key={e.code} value={e.code}>{e.code} - {e.intitule}</option>)}
            </Select>
            <Select label="Utilisateur *" value={form.utilisateur} onChange={(e) => champ('utilisateur', e.target.value)}>
              <option value="">-- Choisir un utilisateur --</option>
              {(utilisateurs ?? []).filter((u) => u.actif).map((u) => (
                <option key={u.id} value={u.id}>{u.login}{u.name && u.name !== u.login ? ` - ${u.name}` : ''}</option>
              ))}
            </Select>
            <Select label="Rôle *" value={form.role} onChange={(e) => champ('role', e.target.value)}>
              <option value="">-- Choisir un rôle --</option>
              {(roles ?? []).map((r) => <option key={r.id} value={r.id}>{r.nom}</option>)}
            </Select>
            {estRoleParent && <SelecteurEleves selection={form.eleves} onChange={(eleves) => champ('eleves', eleves)} />}

            {erreur && <p className="text-sm" style={{ color: 'var(--danger-text)' }}>{erreur}</p>}

            <BoutonsFormulaire
              onReinitialiser={() => { setForm(VIDE); setErreur(null) }}
              enCours={affecter.isPending}
              desactive={incomplet}
            />
          </form>
        </CarteGrille>
      )}
      droite={(
        <CarteGrille icon={List} titre="Liste des Affectations">
          {erreurListe ? (
            <p className="py-6 text-center text-sm" style={{ color: 'var(--danger-text)' }}>
              {messageApi(erreurListe, 'Les affectations n’ont pas pu être chargées.')}
            </p>
          ) : (
            <TableConsole
              colonnes={colonnes}
              lignes={affectations}
              chargement={isLoading}
              vide="Aucune affectation enregistrée."
            />
          )}
        </CarteGrille>
      )}
    />
  )
}
