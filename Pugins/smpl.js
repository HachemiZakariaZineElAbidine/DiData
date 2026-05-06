{
  data() {
    // CONSTANTES
    const GRID_ORIGIN = [100, 180];
    const GRID_STEP = [300, 140];
    
    return {
      // CONFIGURATION INITIALE
      isLoading: true,
      loadingStep: 0,
      loadingSteps: [
        "Loading resources...",
        "Processing data...",
        "Setting up graphics...",
        "Preparing workflow...",
        "Finalizing setup..."
      ],
      loadingProgress: 0,
      loadingMessage: "Loading...",
      
      // DONNÉES DE BASE
      links: [],
      workflow: null,
      workflows: [],
      steps: [],
      pipeline: [],
      entityTypes: [],
      counts: [],
      selected: [],
      toBeSelected: [],
      width: 100,
      height: 100,
      grid: {
        "origin": GRID_ORIGIN,
        "step": GRID_STEP
      },
      zoom: null,
      zoomScale: 1,
      
      // ÉTAT DE L'APPLICATION
      loading: false,
      currentEntities: {
        "subjects": [],
        "cases": [],
        "kits": [],
        "samples": []
      },
      customViewIds: {
        "subjects": undefined,
        "cases": undefined,
        "kits": undefined,
        "samples": undefined
      },
      pipeline: [],
      forms: [],
      currentEventSamples: [],
      defaultEntity: undefined,
      customViewId: undefined,
      customView: undefined,
      selectedCustomViewEntitiesIds: [],
      customViewData: {
        "title": null,
        "type": null
      },
      changedFormValues: {},
        formCache: new Map(),
  formCacheTTL: 10 * 60 * 1000, // 10 minutes
  formCacheTimestamps: new Map(),
      collectionOngoing: false,
      ongoingCollectionFk: null,
      nonAutoSampleCreation: false,
      sampleCreationFormId: null,
      displayForms: false,
      event_done_by: null,
      currentProject: null,
      activeStatus: [],
      resources: {}
    };
  },

  computed: {
    loadedForms() {
      if (this.forms.length > 0)
        return this.forms.every(form => form.form);
    },
    isValidforms() {
      return this.forms.every(form => form.isValidForm);
    }
  },

  methods: {
    //==========================
    // GESTION DU CHARGEMENT
    //==========================
    
    updateLoadingProgress(step) {
      this.loadingStep = step;
    },

    //==========================
    // GESTION DE L'AFFICHAGE
    //==========================
    
    onResize() {
      this.height = window.innerHeight - 71;
      this.width = window.innerWidth - 8;
    },
    
    handleZoom(e) {
      this.zoomScale = e.transform.k;
      d3.selectAll(".layer").attr('transform', e.transform);
    },
    
    initZoom() {
      d3.select('svg')
        .call(this.zoom);
    },
    
    applyCssStyle() {
      // Mettre à jour le height de la grid
      const css = '.body { height: 500px !important; }';
      const head = document.head || document.getElementsByTagName('head')[0];
      const style = document.createElement('style');
      head.appendChild(style);
      style.type = 'text/css';
      if (style.styleSheet) {
        // This is required for IE8 and below.
        style.styleSheet.cssText = css;
      } else {
        style.appendChild(document.createTextNode(css));
      }

      d3.select("#developpedBySBP").append("img").attr("src", this.resources.LOGO_SBP);
    },

    //==========================
    // ROUTES ET RESSOURCES API
    //==========================
    
    async getResources(resources) {
      // get folders
      const folders = await this.dapp.$axios.$get(`/folders`);
      const folderId = folders.find(folder => folder.name == "smpl_resources")?.id;
      
      if (folderId) {
        const files = await this.dapp.$axios.$get(`/folders/${folderId}/files`);
        
        for (let i = 0; i < resources.length; i++) {
          const resource = resources[i];
          const fileId = files.find(file => file.name == resource.name).id;
          
          if (fileId) {
            const link = await this.dapp.$axios.$get(`/files/download-link/${fileId}`);
            this.resources[resource.key] = link.link.replace("smia_chuv", "chuv").replace("http://", "https://");
          } else {
            await this.$toastNotifier.notifyError('Missing file: ' + resource.name);
          }
        }
      } else {
        await this.$toastNotifier.notifyError('Missing folder: smpl_resources');
      }
    },
    
    async getRouteURLByName(name) {
      const routes = await this.dapp.$axios.$get(`/user-routes`);
      const route = routes.find(route => route.name == name).url.replace("smia_chuv", "chuv").replace("http://", "https://");
      const currentProject = $nuxt.$store.getters['currentUser/getCurrentProject'];
      return `${route}?projectId${currentProject.id ? "=" + currentProject.id : ""}`;
    },

    //==========================
    // MÉTHODES DE RÉCUPÉRATION DE DONNÉES
    //==========================
    
    async getEntity(id) {
      if (id) {
        return await this.dapp.$axios.$get(`/entities/${id}`);
      }
      return undefined;
    },
    
    async getSamples(ids) {
      let uri = await this.getRouteURLByName('smpl_get_samples_by_steps');
      for (let i = 0; i < ids.length; i++) {
        uri += (i == 0 ? '&steps[]=' : '&steps[]=') + ids[i];
      }
      return await this.dapp.$axios.$get(uri);
    },
    
    async getSamplesByKit(kitId) {
      let uri = await this.getRouteURLByName('smpl_get_samples_by_kit');
      uri += '&kit=' + kitId;
      return await this.dapp.$axios.$get(uri);
    },
    
    getEventTypeByName(name) {
      return this.workflow.eventTypes.find(et => et.smpl_label == name);
    },
    
    getEventTypeById(id) {
      return this.workflow.eventTypes.find(et => et.id == id);
    },
    
    getEntityType(name) {
      return this.entityTypes.find(et => et.name == name);
    },
    
    getFieldByName(name) {
      return $nuxt.$store.state.fields.fields.find(field => field.name == name);
    },

    //==========================
    // MÉTHODES UTILITAIRES
    //==========================
    
    async setFormIds() {
      const forms = await this.dapp.$axios.$get('/forms');
      this.sampleCreationFormId = forms.find(form => form.name == "smpl_creation_prompt").id;
    },
    
    getStep(stepId) {
      const batch = this.workflow.batches.find(batch => batch.id == stepId);
      if (batch) return batch;
      
      for (let l = 0; l < this.workflow.lines.length; l++) {
        const line = this.workflow.lines[l];
        for (let s = 0; s < line.steps.length; s++) {
          const step = line.steps[s];
          if (step.id == stepId) return step;
        }
      }
      return undefined;
    },
    
    getLine(lineId) {
      return this.workflow.lines.find(line => line.id == lineId);
    },
    
    getBatch(id) {
      return this.workflow.batches.find(batch => batch.id == id);
    },
    
    getChoiceId(categoryName, choiceValue) {
      const category = this.$store.state.fields.choiceCategories.find(category => category.name == categoryName);
      return category._choices.find(choice => choice.value == choiceValue).id;
    },
    
    getChoiceValue(choiceId) {
      let value;
      this.$store.state.fields.choiceCategories.forEach(category => {
        const choice = category._choices.find(choice => choice.id == choiceId);
        if (choice) value = choice.value;
      });
      return value;
    },
    
    getChoiceDescription(choiceId) {
      let description;
      this.$store.state.fields.choiceCategories.forEach(category => {
        const choice = category._choices.find(choice => choice.id == choiceId);
        if (choice) description = choice.description;
      });
      return description;
    },
    
    getStatusId(label) {
      const status = this.workflow.statuses.find(status => status.smpl_label == label);
      return status?.id;
    },
    
    statusIsActive(id) {
      const status = this.workflow.statuses.find(status => status.id == id);
      return status?.smpl_status_is_active;
    },
    
    async getAllWorkflows(id = null) {
      let is_wf = false;
      if (id) {
        const entity = await this.dapp.$axios.$get(`/entities/${id}`);
        if (entity?.smpl_study_fk) is_wf = true;
      }
      
      const response = await this.dapp.$axios.$get(await this.getRouteURLByName('smpl_get_all_workflows'));
      response.filter(workflow => id ? (is_wf ? workflow.id == id : workflow.smpl_study_fk == id) : true).forEach(workflow => {
        this.workflows.push(workflow);
      });
    },
    
    getAllEntityTypes() {
      this.entityTypes = this.$store.state.entityTypes.entityTypes;
      this.entityTypes.forEach(et => {
        et["_fields"] = [];
      });
      
      const fields = this.$store.state.fields.fields;
      fields.forEach(field => {
        field._entitytypes.forEach(fet => {
          let et = this.entityTypes.find(et => et.id == fet.id);
          if (et) et["_fields"].push(field);
        });
      });

      this.entityTypes.forEach(et => { 
        et.name = et.name.replace("_ce_", "_");
      });
    },

    //==========================
    // GESTION DE LA VISUALISATION DU WORKFLOW
    //==========================
    
    setHorizontalPositions() {
      const workflowLines = this.workflow.lines.filter(line => line.visible && line?.steps.length > 0);

      let positions = [];
      for (let i = 0; i < workflowLines.length; i++) {
        var line = workflowLines[i];
        if (line?.smpl_workflow_line_fk) {
          let index = positions.findLastIndex(position => position.smpl_workflow_line_fk == line.smpl_workflow_line_fk);
          if (index < 0) index = positions.findIndex(position => position.id == line.smpl_workflow_line_fk);

          positions.splice(index + 1, 0, line);
        } else {
          positions.push(line);
        }
      }
      
      for (let i = 0; i < positions.length; i++) {
        var line = positions[i];
        line.x = i + 1;
      }
    },
    
    async setVerticalPositions() {
      this.workflow.batches.forEach(batch => {
        batch.xMax = 0;
      });
      
      const lines = this.workflow.lines.filter(line => line.visible && line?.steps.length > 0);

      lines.forEach(line => {
        var position = 0;
        if (this.workflow.smpl_workflow_show_hierarchy) {
          if (line?.smpl_workflow_line_fk) {
            position = this.getStep(line?.smpl_workflow_step_fk).y;
          }
        } else {
          if (line?.smpl_workflow_line_fk) {
            const parentLine = this.getLine(line.smpl_workflow_line_fk);
            position = parentLine.steps[0].y + 1;
          }
        }
        
        line.steps.sort((a, b) => (a.fy ? a.fy : a.y) - (b.fy ? b.fy : b.y));
        
        line.steps.forEach((step, index) => {
          if (step?.smpl_workflow_step_batch_fk) {
            const batch = this.workflow.batches.find(batch => batch.id == step.smpl_workflow_step_batch_fk);
            if (!batch?.y) batch.y = 0;
            batch.y = Math.max(position, batch.y);
            position = batch.y;
            batch.xMin = batch.xMin ? Math.min(batch.xMin, step.x) : step.x;
            batch.xMax = batch.xMax ? Math.max(batch.xMax, step.x) : step.x;
          }
          
          while (this.workflow.batches.find(batch => batch.y == position && batch.id != step?.smpl_workflow_step_batch_fk)) {
            position++;
          }
          
          step.y = line.smpl_workflow_line_is_kit && step.smpl_order == 0 ? 0 : position;
          step.x = line.x;
          position++;
        });
      });
    },
    
    aggregateSteps() {
      this.steps = [];

      this.workflow.batches.forEach(batch => {
        batch.active = false;
      });
      
      this.workflow.lines.filter(line => line.visible).forEach(line => {
        line.steps.forEach(step => {
          if (this.getEventTypeById(step.smpl_event_type_fk).smpl_label == "Collection" && this.currentEntities.subjects.length == 1 && this.currentEntities.cases.length == 1) {
            step.active = true;
          } else {
            step.active = false;
          }
          
          this.currentEntities.samples
            .filter(sample => this.currentEntities.subjects.length > 0 ? this.currentEntities.subjects.find(subject => subject.id == sample.smpl_subject_fk) || !sample.smpl_subject_fk : true)
            .filter(sample => this.currentEntities.cases.length > 0 ? this.currentEntities.cases.find(casus => casus.id == sample.smpl_case_fk) || !sample.smpl_case_fk : true)
            .filter(sample => this.currentEntities.kits.length > 0 ? this.currentEntities.kits.find(kit => kit.id == sample.smpl_kit_fk) || !sample.smpl_kit_fk : true)
            .forEach(sample => {
              if (step?.prevSteps.includes(sample.smpl_workflow_step_fk)) {
                if (step.smpl_workflow_step_batch_fk) this.getBatch(step.smpl_workflow_step_batch_fk).active = true;
                step.active = true;
              }
            });
            
          this.steps.push(step);
        });
      });
    },
    
    batchAlignment() {
      this.workflow.batches.forEach(batch => {
        const steps = this.steps.filter(step => step?.smpl_workflow_step_batch_fk == batch.id);
        var lowest = 0;
        steps.forEach(step => { lowest = Math.max(lowest, step.y); });
        batch.y = lowest;
        steps.forEach(step => { step.yBatch = batch.y; });
      });
      
      this.setVerticalPositions();
    },
    
    removeEmptyRows() {
      var rowCount = 0;
      this.steps.forEach(step => {
        rowCount = Math.max(rowCount, step.y);
      });
      
      var positions = Array(rowCount + 1).fill();
      this.steps.forEach(step => {
        if (!positions[step.y]) positions[step.y] = [step.id];
        else positions[step.y].push(step.id);
      });
      
      positions = positions.filter(d => d);

      positions.forEach((elements, index) => {
        if (elements) {
          elements.forEach(id => {
            var step = this.getStep(id);
            step.y = index;
            if (step?.smpl_workflow_step_batch_fk) {
              const batch = this.workflow.batches.find(batch => batch.id == step.smpl_workflow_step_batch_fk);
              batch.y = index;
            }
          });
        }
      });
    },
    
setLinks() {
  
  const lines = this.workflow.lines.filter(line => line.visible && line?.steps.length > 0);
  this.links = [];
  
  
  let skippedLines = [];
  let createdLinks = 0;
  
  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];
    
    
    // ✅ VÉRIFICATION : Au moins 2 steps
    if (!line.steps || line.steps.length < 2) {
      const warning = {
        lineId: line.id,
        label: line.smpl_label,
        stepsCount: line.steps?.length || 0,
        reason: 'Needs at least 2 steps to create links'
      };
      skippedLines.push(warning);
      continue;
    }
    
    var parentStep = line.smpl_workflow_line_fk 
      ? this.getStep(line.smpl_workflow_step_fk) 
      : { id: (this.workflow?.smpl_workflow_is_collection ? -1 : null), x: 0, y: 0 };


    if (parentStep.id) {
      const optionalOffset = 0.12;
      const sampleStep = line.steps[1];
      

      // Premier link
      this.links.push({
        id: (parentStep.id * 10000 + sampleStep.id) * 10,
        source: [((parentStep.x + (parentStep.smpl_workflow_step_is_optional ? optionalOffset : 0)) + 0.78 * (this.grid.step[1] / this.grid.step[0])) * this.grid.step[0], (parentStep.y + 0.78) * this.grid.step[1]],
        target: [(sampleStep.x - 0.22 * (this.grid.step[1] / this.grid.step[0])) * this.grid.step[0], (parentStep.y + 0.78) * this.grid.step[1]],
        origin: [parentStep.x * this.grid.step[0], parentStep.y * this.grid.step[1]],
        dashed: true
      });
      createdLinks++;
      
      if (this.workflow.smpl_workflow_show_hierarchy) {
        this.links.push({
          id: parentStep.id * 1000 + sampleStep.id,
          source: [(sampleStep.x - 0.22 * (this.grid.step[1] / this.grid.step[0])) * this.grid.step[0], (parentStep.y + 0.78) * this.grid.step[1]],
          target: [sampleStep.x * this.grid.step[0], sampleStep.y * this.grid.step[1]],
          origin: [parentStep.x * this.grid.step[0], parentStep.y * this.grid.step[1]],
          dashed: true
        });
        createdLinks++;
      } else {
        this.links.push({
          id: parentStep.id * 1000 + sampleStep.id,
          source: [(sampleStep.x - 0.22 * (this.grid.step[1] / this.grid.step[0])) * this.grid.step[0], (parentStep.y + 0.78) * this.grid.step[1]],
          target: [(sampleStep.x - 0.22 * (this.grid.step[1] / this.grid.step[0])) * this.grid.step[0], sampleStep.y * this.grid.step[1]],
          origin: [parentStep.x * this.grid.step[0], parentStep.y * this.grid.step[1]],
          dashed: true
        });
        createdLinks++;
        
        this.links.push({
          id: parentStep.id * 100000 + sampleStep.id * 100,
          source: [(sampleStep.x - 0.22 * (this.grid.step[1] / this.grid.step[0])) * this.grid.step[0], sampleStep.y * this.grid.step[1]],
          target: [sampleStep.x * this.grid.step[0], sampleStep.y * this.grid.step[1]],
          origin: [parentStep.x * this.grid.step[0], parentStep.y * this.grid.step[1]],
          dashed: true
        });
        createdLinks++;
      }
      
    } else {
    }
  }
  
  // ✅ RÉSUMÉ
  
  if (skippedLines.length > 0) {
  }
  
},
    
    async updateCounts() {
      this.steps = [];

      this.workflow.lines.forEach(line => {
        line.steps.forEach(step => {
          this.steps.push(step);
        });
      });
      
      let uri = await this.getRouteURLByName('smpl_get_sample_counts');
      this.steps.forEach(step => {
        uri += '&stepId[]=' + step.id;
      });
      
      this.currentEntities.kits.forEach(kit => {
        if (kit.smpl_kit_is_real) uri += '&kit[]=' + kit.id;
      });
      
      this.currentEntities.cases.forEach(casus => {
        uri += '&casus[]=' + casus.id;
      });
      
      this.currentEntities.subjects.forEach(subject => {
        uri += '&subject[]=' + subject.id;
      });
      
      const response = await this.dapp.$axios.$get(uri);

      this.steps.forEach(step => {
        step.count = response[step.id];
        step.total = { count: 0, selected: 0 };
        
        if (step.count) {
          step.count.forEach(c => {
            step.total.count += c.count;
            c.selected = this.currentEntities.samples
              .filter(sample => sample.smpl_sample_status_fk == c.status.id)
              .filter(sample => sample.smpl_workflow_step_fk == step.id)
              .filter(sample => this.currentEntities.subjects.length > 0 ? this.currentEntities.subjects.find(subject => subject.id == sample.smpl_subject_fk) || !sample.smpl_subject_fk : true)
              .filter(sample => this.currentEntities.cases.length > 0 ? this.currentEntities.cases.find(casus => casus.id == sample.smpl_case_fk) || !sample.smpl_case_fk : true)
              .filter(sample => this.currentEntities.kits.length > 0 ? this.currentEntities.kits.find(kit => kit.id == sample.smpl_kit_fk) || !sample.smpl_kit_fk : true)
              .length;
            step.total.selected += c.selected;
          });
        }
      });

      this.update();
    },
    
    hideLine(id) {
      this.getLine(id).visible = false;
      this.workflow.lines.filter(line => line?.smpl_workflow_line_fk == id).forEach(subline => {
        this.hideLine(subline.id);
      });
    },
    
    //==========================
    // GESTION DU RENDU GRAPHIQUE
    //==========================
    
  async enterSteps() {
  // Cacher les sélections D3 fréquemment utilisées
  if (!this.d3Cache) {
    this.d3Cache = {
      nodeLayer: d3.select("#nodeLayer"),
      lineLayer: d3.select("#lineLayer"),
      batchLayer: d3.select("#batchLayer"),
      countLayer: d3.select("#countLayer")
    };
  }
  
  const steps = this.steps;
  const visibleLines = this.workflow.lines.filter(line => line.visible);
  const optionalOffset = 0.12;
  
  // Fonction de wrapping de texte optimisée
  function wrap(text, width) {
    text.each(function() {
      const text = d3.select(this);
      const words = text.text().split(/\s+/).reverse();
      const lineHeight = 1.1;
      const x = text.attr("x");
      const y = text.attr("y");
      let lineNumber = 0;
      let word;
      let line = [];
      
      let tspan = text.text(null)
        .append("tspan")
        .attr("x", x)
        .attr("y", y)
        .attr("dy", 0 + "em");
        
      while (word = words.pop()) {
        line.push(word);
        tspan.text(line.join(" "));
        if (tspan.node() && tspan.node().getComputedTextLength() > width) {
          line.pop();
          tspan.text(line.join(" "));
          line = [word];
          
          if (lineNumber < 1) {
            tspan = text.append("tspan")
              .attr("x", x)
              .attr("y", y)
              .attr("dy", ++lineNumber * lineHeight + "em")
              .text(word);
          } else {
            tspan.text(tspan.text() + "…");
            break;
          }
        }
      }
    });
  }

  // ======== LIGNES ÉCHANTILLONS ========
  // Utiliser une seule sélection et un chaînage de méthodes pour réduire les accès DOM
  const sampleLines = this.d3Cache.lineLayer.selectAll(".sampleLine")
    .data(visibleLines, d => d.id);
  
  // Supprimer les lignes obsolètes
  sampleLines.exit().remove();
  
  // Créer les nouvelles lignes
  const sampleLineEnter = sampleLines.enter()
    .append("g")
    .classed("sampleLine", true);

  // Ajouter le texte des lignes
  sampleLineEnter.append("text")
    .attr("fill", "#299D8F")
    .style("text-anchor", "start")
    .style("font-size", "1.4em")
    .style("font-weight", 700)
    .style("cursor", "default")
    .attr('x', -10)
    .attr('y', d => (this.getLine(d.id).steps[0].y - 0.5) * this.grid.step[1])
    .text(d => (d.smpl_workflow_line_quantity ? d.smpl_workflow_line_quantity + "× " : "") + (d.smpl_label ? d.smpl_label : ""))
    .call(wrap, this.grid.step[0] - 40);

  // Ajouter les lignes principales et de fin
  sampleLineEnter.append("line")
    .attr("class", "bigLine")
    .attr("stroke", d => {
      const color = this.getChoiceDescription(d.smpl_workflow_line_color);
      return color ? color : "darkGray";
    })
    .attr("stroke-width", 8);

  sampleLineEnter.append("line")
    .attr("class", "endLine")
    .attr("stroke", d => {
      const color = this.getChoiceDescription(d.smpl_workflow_line_color);
      return color ? color : "darkGray";
    })
    .attr("stroke-width", 4);

  // Mettre à jour toutes les lignes (nouvelles et existantes)
  const sampleLineUpdate = sampleLines.merge(sampleLineEnter);
  
  sampleLineUpdate.transition()
    .duration(300)
    .attr("transform", d => {
      return "translate(" + (d.steps[0].x * this.grid.step[0]) + ",0)scale(1)";
    });

  sampleLineUpdate.select("text")
    .attr('x', -10)
    .attr('y', d => (this.getLine(d.id).steps[0].y - 0.5) * this.grid.step[1]);
  
  sampleLineUpdate.select(".bigLine")
    .attr("x1", 0)
    .attr("y1", d => this.getLine(d.id).steps[0].y * this.grid.step[1])
    .attr("x2", 0)
    .attr("y2", d => {
      const steps = this.getLine(d.id).steps;
      const endStep = steps[steps.length - 1];
      return (endStep.y + 0.4) * this.grid.step[1];
    });
    
  sampleLineUpdate.select(".endLine")
    .attr("x1", -12)
    .attr("y1", d => {
      const steps = this.getLine(d.id).steps;
      const endStep = steps[steps.length - 1];
      return (endStep.y + 0.4) * this.grid.step[1];
    })
    .attr("x2", 12)
    .attr("y2", d => {
      const steps = this.getLine(d.id).steps;
      const endStep = steps[steps.length - 1];
      return (endStep.y + 0.4) * this.grid.step[1];
    });

  // ======== ÉTAPES ========
  const nodes = this.d3Cache.nodeLayer.selectAll(".node")
    .data(steps, d => d.id);
    
  // Supprimer les étapes obsolètes  
  nodes.exit().remove();
  
  // Créer les nouvelles étapes
  const nodesEnter = nodes.enter()
    .append("g")
    .attr("class", d => "n" + d.id)
    .classed("node", true)
    .style("opacity", 0);

  // Ajouter le chemin optionnel
  nodesEnter.append("path")
    .classed("optionalPath", true)
    .attr("d", d => "M " + (-optionalOffset * this.grid.step[0]) + ", -35 L 0, -35 L 0, 75, L " + (-optionalOffset * this.grid.step[0]) + ", 75")
    .style("fill", "none")
    .attr('stroke', d => {
      const color = this.getChoiceDescription(this.getLine(d.smpl_workflow_line_fk).smpl_workflow_line_color);
      return color ? color : "darkGray";
    })
    .attr('stroke-width', 5)
    .style("stroke-dasharray", "5, 5")
    .style('opacity', 0);

  // Ajouter les dérivations
  const derivation = nodesEnter.append("g")
    .classed("derivation", true)
    .style("visibility", d => d.type == "Aliquoting" ? "visible" : "hidden");
    
  derivation.append("line")
    .attr("x1", 0)
    .attr("y1", 0)
    .attr("x2", 0.78 * this.grid.step[1])
    .attr("y2", 0.78 * this.grid.step[1])
    .attr('stroke', "#000")
    .attr('stroke-width', 3)
    .style("stroke-dasharray", "0, 6")
    .style('stroke-linecap', 'round');

  derivation.append("text")
    .style("font-size", "0.8em")
    .style("text-decoration", "underline")
    .style("cursor", "pointer")
    .attr("x", 0.78 * this.grid.step[1] + 2)
    .attr("y", 0.78 * this.grid.step[1] - 5)
    .on("click", (e, d) => {
      // Utiliser un index de lignes dérivées pour éviter des recherches répétées
      const derivedLines = this.workflow.lines.filter(line => line.smpl_workflow_step_fk == d.id);
      derivedLines.forEach(line => {
        if (line.visible) {
          d.open = false;
          this.hideLine(line.id);
        } else {
          d.open = true;
          line.visible = true;
        }
      });
      this.update();
    });

  // Ajouter les icônes d'entrée
  nodesEnter.append("path")
    .classed("inputIcon", true)
    .style("visibility", d => d.type == "Input" ? "visible" : "hidden")
    .attr("d", "M -14,-10 L 14,-10 L 0,10 Z")
    .attr("stroke", d => {
      const color = this.getChoiceDescription(this.getLine(d.smpl_workflow_line_fk).smpl_workflow_line_color);
      return color ? color : "darkGray";
    })
    .attr('stroke-width', 4)
    .attr('fill', 'white');

  // Ajouter les cercles pour les icônes
  nodesEnter.append("circle")
    .classed("icon", true)
    .style("visibility", d => ["Input"].includes(d.type) ? "hidden" : "visible")
    .attr('cx', 0)
    .attr('cy', 0)
    .attr('r', 10)
    .style("fill", "white");

  // Ajouter les éléments de texte
  nodesEnter.append("text")
    .classed("nodeType", true)
    .attr("fill", "black")
    .style("text-anchor", "start")
    .style("font-size", "0.7em")
    .style("cursor", "default")
    .attr('x', 18)
    .attr('y', -13);

  nodesEnter.append("text")
    .classed("nodeName", true)
    .attr("fill", "black")
    .style("text-anchor", "start")
    .style("font-weight", "700")
    .attr('x', 18)
    .attr('y', 6)
    .on("click", async (e, d) => {
      if (d.active) this.standardEvent(d);
    });

  nodesEnter.append("text")
    .classed("nodeId", true)
    .style("text-anchor", "start")
    .style("font-size", "0.5em")
    .style("cursor", "default")
    .attr("fill", "#A0A0A0")
    .style("opacity", 0.5)
    .attr('x', 18)
    .attr('y', 20);

  nodesEnter.append("text")
    .classed("nodeThen", true)
    .attr("fill", "black")
    .style("text-anchor", "start")
    .style("font-size", "0.7em")
    .style("cursor", "default")
    .attr('x', 18)
    .attr('y', 40);

  // Mettre à jour toutes les étapes (nouvelles et existantes)
  const nodesUpdate = nodes.merge(nodesEnter).classed("active", d => d.active);

  // Mettre à jour les propriétés des étapes
  nodesUpdate.select(".optionalPath")
    .style("opacity", d => d.smpl_workflow_step_is_optional ? 1 : 0);

  nodesUpdate.select(".icon")
    .attr('cx', 0)
    .attr('cy', 0)
    .attr('r', 9)
    .attr("stroke", d => {
      const color = this.getChoiceDescription(this.getLine(d.smpl_workflow_line_fk).smpl_workflow_line_color);
      return color ? color : "darkGray";
    })
    .attr('stroke-width', 4);

  nodesUpdate.selectAll('text')
    .attr("fill", d => d.active ? "black" : "DimGray");

  // Mettre en cache les dérivations pour éviter des recherches répétées
  const derivationCache = {};
  this.workflow.lines.forEach(line => {
    if (line.smpl_workflow_step_fk) {
      if (!derivationCache[line.smpl_workflow_step_fk]) {
        derivationCache[line.smpl_workflow_step_fk] = [];
      }
      derivationCache[line.smpl_workflow_step_fk].push(line);
    }
  });
  
  nodesUpdate.select(".derivation")
    .select("text")
    .html(d => {
      // Utiliser le cache pour une recherche plus rapide
      const derivedLines = derivationCache[d.id] || [];
      const areVisible = derivedLines.length > 0 && derivedLines.every(line => line.visible);
      return areVisible ? "hide" : "show";
    });

  // Mettre à jour les types d'étapes
  nodesUpdate.select('.nodeType')
    .text(d => {
      if (d.smpl_label) {
        const eventType = this.getEventTypeByName(d.type);
        if (eventType && eventType.smpl_is_alias) {
          return "alias to " + d.smpl_workflow_step_goto_fk;
        } else {
          return d.type + (d.smpl_workflow_step_is_optional ? " (opt.)" : "");
        }
      }
      return "";
    });

  // Mettre à jour les noms d'étapes
  nodesUpdate.select('.nodeName')
    .style("cursor", d => d.active ? "pointer" : "default")
    .text(d => d.smpl_label ? d.smpl_label : d.type)
    .call(wrap, this.grid.step[0] - 40);

  // Mettre à jour les textes additionnels
  nodesUpdate.select('.nodeThen')
    .text(d => d.smpl_workflow_step_then ? "then: " + d.smpl_workflow_step_then : "")
    .call(wrap, this.grid.step[0] - 100);

  nodesUpdate.select('.nodeId')
    .text(d => d?.smpl_order + ": " + d.id);

  // Animer la transition des étapes
  nodesUpdate.transition()
    .duration(300)
    .attr("transform", d => {
      return "translate(" + ((d.x + (d.smpl_workflow_step_is_optional ? optionalOffset : 0)) * this.grid.step[0]) + "," + ((d.dragged ? d.fy : d.y) * this.grid.step[1]) + ")scale(1)";
    })
    .style("opacity", 1);

  // ======== LOTS ========
  const batches = this.d3Cache.batchLayer.selectAll(".batch")
    .data(this.workflow.batches, d => d.id);
  
  // Supprimer les lots obsolètes
  batches.exit().remove();
  
  // Créer les nouveaux lots
  const batchEnter = batches.enter()
    .append("g")
    .classed("batch", true)
    .style("opacity", 0);

  // Ajouter la ligne principale
  batchEnter.append('line')
    .attr('stroke', "#EEE")
    .attr('stroke-width', 72)
    .attr("x1", d => (0.6) * this.grid.step[0])
    .attr("y1", d => d.y * this.grid.step[1])
    .attr("x2", d => (d.xMax + 1) * this.grid.step[0])
    .attr("y2", d => d.y * this.grid.step[1]);

  // Ajouter l'image
  batchEnter.append("image")
    .attr("xlink:href", this.resources.batch)
    .attr("width", 30)
    .attr("height", 30)
    .attr("x", 0.6 * this.grid.step[0] + 20)
    .attr("y", d => d.y * this.grid.step[1] - 15)
    .on("click", async (e, d) => {
      if (d.active) this.standardEvent(d);
    });

  // Mettre à jour tous les lots (nouveaux et existants)
  const batchUpdate = batches.merge(batchEnter);
  
  // Animer la transition des lots
  batchUpdate.transition()
    .duration(300)
    .style("opacity", 1);

  batchUpdate.select("image")
    .style("opacity", d => d?.active ? 0.8 : 0.3)
    .attr("y", d => d.y * this.grid.step[1] - 15)
    .style("cursor", d => d.active ? "pointer" : "default");

  batchUpdate.select("line")
    .attr("x1", d => (0.6) * this.grid.step[0])
    .attr("y1", d => d.y * this.grid.step[1])
    .attr("x2", d => (d.xMax + 1) * this.grid.step[0])
    .attr("y2", d => d.y * this.grid.step[1]);

  // ======== COMPTEURS ========
  // Ne sélectionner que les étapes avec des compteurs
  const stepsWithCounts = this.steps.filter(step => step.total.count > 0);
  
  // Supprimer d'abord les compteurs qui ne sont plus nécessaires
  const countIds = new Set(stepsWithCounts.map(step => "c" + step.id));
  this.d3Cache.countLayer.selectAll(".count").each(function() {
    const id = d3.select(this).attr("id");
    if (!countIds.has(id)) {
      d3.select(this).remove();
    }
  });
  
  // Ensuite, traiter individuellement chaque étape
  stepsWithCounts.forEach(step => {
    // Vérifier si le compteur existe déjà
    let countElement = this.d3Cache.countLayer.select("#c" + step.id);
    
    // Si le compteur n'existe pas, le créer
    if (countElement.empty()) {
      countElement = this.d3Cache.countLayer.append("g")
        .classed("count", true)
        .attr("id", "c" + step.id)
        .attr("transform", `translate(${step.x * this.grid.step[0]},${(step.y + 0.4 - 1) * this.grid.step[1]})`)
        .style("opacity", 1);
      
      // Ajouter les éléments de base du compteur
      countElement.append("rect").classed("countStatusBoxOutside", true)
        .attr("x", 0).attr("y", -3)
        .attr("width", 0).attr("height", 0)
        .attr("rx", 100).attr("ry", 100)
        .style("fill", "#565656")
        .style("fill-opacity", 0.0);
      
      countElement.append("rect").classed("countStatusBoxInside", true)
        .attr("x", 0).attr("y", -3)
        .attr("width", 0).attr("height", 0)
        .attr("rx", 100).attr("ry", 100)
        .style("fill", "#565656")
        .style("fill-opacity", 0.0);
      
      countElement.append("rect").classed("countValueBox", true)
        .attr("x", 0).attr("y", -3)
        .attr("width", 0).attr("height", 0)
        .attr("rx", 100).attr("ry", 100)
        .style("stroke", "#565656")
        .style("stroke-width", 3)
        .style("fill", "white")
        .style("fill-opacity", 0.0)
        .style("cursor", "pointer")
        .on("click", async () => {
          this.toBeSelected = [];
          await this.showCustomViewForStep(step.id);
        });
      
      countElement.append("text").classed("countValues", true)
        .attr("x", 0).attr("y", 0)
        .style("font-size", "0.8em")
        .style("fill", "#565656")
        .style("cursor", "pointer")
        .style("alignment-baseline", "middle");
    }
    
    // Mettre à jour la position du compteur
    countElement.transition()
      .duration(300)
      .attr("transform", `translate(${(step.x + (step.smpl_workflow_step_is_optional ? optionalOffset : 0)) * this.grid.step[0]},${(step.y + 0.45) * this.grid.step[1]})`);
    
    // Mettre à jour le contenu du compteur
    const text = countElement.select("text");
    text.html("");
    
    if (step.count && step.count.length > 0) {
      // Variables pour le positionnement
      let dy = 0.2 * 12;
      let lineHeight = 1.2 * 12;
      
      // Compteur principal
      text.append("tspan")
        .attr("x", 0)
        .attr("y", 0)
        .attr("dy", 0)
        .text(step.total.selected ? step.total.selected : step.total.count)
        .style("font-weight", 700)
        .style("font-size", "1.2em")
        .style("text-anchor", "middle")
        .style("alignment-baseline", "central")
        .on("click", async () => {
          this.toBeSelected = [];
          await this.showCustomViewForStep(step.id);
        });
      
      // Mesurer la taille du texte après le rendu
      const bbox = text.node().getBBox();
      
      // Texte d'information
      text.append("tspan")
        .attr("y", -3)
        .attr("x", 26 - bbox.x)
        .attr("dy", 0)
        .text(step.total.count + " SAMPLE" + (step.total.count > 1 ? "S" : "") + (step.total.selected ? " (" + step.total.selected + ")" : ""))
        .style("font-weight", 700)
        .style("font-size", "0.9em")
        .style("alignment-baseline", "central")
        .style("fill", "white")
        .on("mouseenter", function() {
          d3.select(this).style("text-decoration", "underline");
        })
        .on("mouseleave", function() {
          d3.select(this).style("text-decoration", "none");
        })
        .on("click", async () => {
          this.toBeSelected = [];
          await this.showCustomViewForStep(step.id);
        });
      
      // Bouton "all/none"
      const allNoneButton = text.append("tspan")
        .attr("y", -3)
        .attr("x", this.grid.step[0] * 0.5 + 10)
        .attr("dy", 0)
        .text(step.total.selected == step.total.count ? "none" : "all")
        .style("opacity", 0.5)
        .style("font-size", "0.6em")
        .style("text-anchor", "end")
        .style("alignment-baseline", "central")
        .style("fill", "white")
        .on("mouseenter", function() {
          d3.select(this).style("text-decoration", "underline");
        })
        .on("mouseleave", function() {
          d3.select(this).style("text-decoration", "none");
        })
       // Dans la fonction qui ajoute le bouton "all" principal
.on("click", async (e) => {
  // Capturer le bouton pour les mises à jour sécurisées
  const button = d3.select(e.target);
  
  try {
    // Désactiver les interactions pendant le traitement
    button.text("...").style("pointer-events", "none");
    
    // Obtenir tous les échantillons de cette étape
    const samples = await this.getSamples([step.id]);
    
    // Déterminer si on doit sélectionner tous ou désélectionner tous
    const shouldSelectAll = step.total.selected < step.total.count;
    
    if (shouldSelectAll) {
      // Sélectionner tous les échantillons
      this.selectSamples(samples);
    } else {
      // Désélectionner tous les échantillons de cette étape
      const currentlySelectedSamples = this.currentEntities.samples
        .filter(sample => sample.smpl_workflow_step_fk === step.id);
      this.unselectSamples(currentlySelectedSamples);
    }
    
    // Mettre à jour l'interface de manière optimiste
    if (shouldSelectAll) {
      // Mettre à jour tous les statuts individuels
      if (step.count) {
        step.count.forEach(c => {
          c.selected = c.count;
        });
      }
      // Mettre à jour le compteur total
      step.total.selected = step.total.count;
    } else {
      // Mettre à jour tous les statuts individuels
      if (step.count) {
        step.count.forEach(c => {
          c.selected = 0;
        });
      }
      // Mettre à jour le compteur total
      step.total.selected = 0;
    }
    
    // Mettre à jour l'interface
    this.updateCountersOnly();
    
  } catch (error) {
    this.updateCountersOnly();
  } finally {
    // Vérifier que le bouton existe encore
    if (button.node()) {
      button.text(step.total.selected === step.total.count ? "none" : "all")
        .style("pointer-events", "auto");
    }
  }
});
      
      // Ajouter les statuts individuels
      step.count.forEach(status => {
        dy += lineHeight;
        
        // Texte du statut
        text.append("tspan")
          .attr("x", 26 - bbox.x)
          .attr("dx", 0)
          .attr("y", dy - 3)
          .text(status.count + " " + status.status.smpl_label.toLowerCase() + (status.selected ? " (" + status.selected + ")" : ""))
          .style("font-weight", 500)
          .style("font-size", "0.8em")
          .style("alignment-baseline", "central")
          .style("fill", "dimgrey")
          .on("mouseenter", function() {
            d3.select(this).style("text-decoration", "underline");
          })
          .on("mouseleave", function() {
            d3.select(this).style("text-decoration", "none");
          })
          .on("click", async () => {
            this.toBeSelected = [];
            await this.showCustomViewForStep(step.id, status.status.id);
          });
        
        // Bouton "all/none" pour ce statut
        text.append("tspan")
          .attr("x", this.grid.step[0] * 0.5 + 10)
          .attr("y", dy - 3)
          .text(status.selected == status.count ? "none" : "all")
          .style("font-weight", 500)
          .style("opacity", 0.4)
          .style("font-size", "0.6em")
          .style("text-anchor", "end")
          .style("alignment-baseline", "central")
          .on("mouseenter", function() {
            d3.select(this).style("text-decoration", "underline");
          })
          .on("mouseleave", function() {
            d3.select(this).style("text-decoration", "none");
          })
          .on("click", async (e) => {
            const button = d3.select(e.target);
            button.text("...");
            button.style("pointer-events", "none");
            
            try {
              const samples = await this.getSamples([step.id]);
              const statusSamples = samples.filter(sample => sample.smpl_sample_status_fk == status.status.id);
              
              if (status.selected == status.count) {
                this.unselectSamples(statusSamples);
              } else {
                this.selectSamples(statusSamples);
              }
            } catch (error) {
            } finally {
              if (button.node()) {
                button.text(status.selected == status.count ? "none" : "all");
                button.style("pointer-events", "auto");
              }
            }
          });
      });
      
      // Mettre à jour les dimensions des rectangles de fond
      const updatedBbox = text.node().getBBox();
      const r = 12;
      const width = Math.max(2 * (r - bbox.x - 6), 2 * r);
      
      countElement.select(".countValueBox")
        .style("fill-opacity", 1)
        .transition()
        .duration(200)
        .attr("x", -width / 2)
        .attr("y", -r)
        .attr("width", width)
        .attr("height", 2 * r)
        .attr("rx", r)
        .attr("ry", r)
        .style("fill", step.total.selected ? "#FFFAC4" : "white");
      
      countElement.select(".countStatusBoxOutside")
        .style("fill-opacity", 1)
        .transition()
        .duration(200)
        .attr("x", 20)
        .attr("y", -13)
        .attr("width", this.grid.step[0] * 0.5)
        .attr("height", updatedBbox.height + updatedBbox.y + 18)
        .attr("rx", 6)
        .attr("ry", 6)
        .style("fill", "#565656");
      
      const csboWeight = 2;
      const firstline = 1.2 * 16;
      
      countElement.select(".countStatusBoxInside")
        .style("fill-opacity", 1)
        .transition()
        .duration(200)
        .attr("x", 20 + csboWeight)
        .attr("y", -13 + firstline)
        .attr("width", this.grid.step[0] * 0.5 - 2 * csboWeight)
        .attr("height", updatedBbox.height + updatedBbox.y + 18 - firstline - csboWeight)
        .attr("rx", 3)
        .attr("ry", 3)
        .style("fill", "white");
    }
  });
},

// Méthode auxiliaire pour mettre à jour uniquement les compteurs
// Méthode auxiliaire pour mettre à jour uniquement les compteurs
updateCountersOnly() {
  // Ne mettre à jour que les compteurs visibles
  this.steps.filter(step => step && step.total && step.total.count > 0).forEach(step => {
    const countElement = d3.select("#c" + step.id);
    if (countElement.empty()) return;
    
    // Mettre à jour le texte du compteur principal
    const mainCounter = countElement.select("text tspan:first-child");
    if (!mainCounter.empty()) {
      mainCounter.text(step.total.selected ? step.total.selected : step.total.count);
    }
    
    // Mettre à jour la couleur de la boîte de compteur
    const countBox = countElement.select(".countValueBox");
    if (!countBox.empty()) {
      countBox.style("fill", step.total.selected ? "#FFFAC4" : "white");
    }
    
    // Mettre à jour le texte récapitulatif
    const summaryText = countElement.select("text tspan:nth-child(2)");
    if (!summaryText.empty()) {
      summaryText.text(step.total.count + " SAMPLE" + 
        (step.total.count > 1 ? "S" : "") + 
        (step.total.selected ? " (" + step.total.selected + ")" : ""));
    }
    
    // Mettre à jour le bouton all/none
    const allNoneButton = countElement.select("text tspan:nth-child(3)");
    if (!allNoneButton.empty()) {
      allNoneButton.text(step.total && step.total.selected == step.total.count ? "none" : "all");
    }
  });
},
    
    enterLinks() {
      d3.select("#linkLayer").selectAll(".link").data(this.links, d => d.id).exit().remove();

      d3.select("#linkLayer").selectAll(".link").data(this.links, d => d.id).enter()
        .append("line").classed("link", true)
        .style("stroke-dasharray", "0, 6")
        .style('stroke-linecap', 'round')
        .attr('stroke', "black")
        .attr('stroke-width', 3)
        .attr("x1", d => d.origin[0])
        .attr("y1", d => d.origin[1])
        .attr("x2", d => d.origin[0])
        .attr("y2", d => d.origin[1]);

      d3.select("#linkLayer").selectAll(".link").data(this.links, d => d.id)
        .transition()
        .style("opacity", 1)
        .attr("x1", d => d.source[0])
        .attr("y1", d => d.source[1])
        .attr("x2", d => d.target[0])
        .attr("y2", d => d.target[1]);
    },
    
    async enterToolbar() {
      const toolbar = d3.select("#toolbar");
      toolbar.html("");

      // Groupe workflow
      const workflowGroup = toolbar.append("div").attr("class", "toolbarGroup");
      const workflowSelector = workflowGroup.append("div").attr("class", "toolbarElement");

      workflowSelector.append("label").html("Workflow");
      const select = workflowSelector.append("select").attr("id", "wfSelector").on("change", (e, d) => { this.loadWorkflow(select.property("value")); });
      
      this.workflows.forEach(wf => {
        select.append("option").property("selected", () => wf.id == this.workflow.id).attr("value", wf.id).html(wf.study + ": " + wf.smpl_label);
      });

      // Groupe cas
      const caseGroup = toolbar.append("div").attr("class", "toolbarGroup");

      const subjectSelector = caseGroup.append("div").attr("class", "toolbarElement");
      subjectSelector.append("label").html("Subject");

      subjectSelector.append("button").html(() => {
        if (this.currentEntities.subjects.length == 1) return this.currentEntities.subjects[0].smpl_id;
        else if (this.currentEntities.subjects.length > 1) return "Multiple";
        return "No subject selected";
      }).on("click", (e) => {
        this.showCustomViewForSubjects();
      });

      const caseTranslation = this.$translate('field', 'label', this.getFieldByName('smpl_case_fk'));

      const caseSelector = caseGroup.append("div").attr("class", "toolbarElement");

      caseSelector.append("label").html(caseTranslation);

      caseSelector.append("button").html(() => {
        if (this.currentEntities.cases.length == 1) return this.currentEntities.cases[0].smpl_id;
        else if (this.currentEntities.cases.length > 1) return "Multiple";
        return "No case selected";
      }).on("click", (e) => {
        this.showCustomViewForCases();
      });

      if (this.workflow.smpl_workflow_uses_kits) {
        caseGroup.append("a").html("Kit").on("click", (e) => {
          this.showCustomViewForKits();
        });
      }

      // Groupe scanner
      const scannerGroup = toolbar.append("div").attr("class", "toolbarGroup");
      scannerGroup.append("input").attr("id", "scanField").attr("type", "text").attr("name", "scanner").attr("placeholder", "Scan")
        .on("change", async e => {
          await this.scan(e.target.value);
          document.getElementById('scanField').value = "";
        });

      // Groupe auxiliaire
      const auxGroup = toolbar.append("div").attr("class", "toolbarGroup");

      auxGroup.append("a")
        .classed("visible", d => {
          let flag = true;
          this.workflow.lines.forEach(line => {
            if (!line.visible) flag = false;
          });
          return flag;
        })
        .style("background-image", d => {
          let flag = true;
          this.workflow.lines.forEach(line => {
            if (!line.visible) flag = false;
          });
          return flag ? "url(" + this.resources.branches_hide + ")" : "url(" + this.resources.branches_show + ")";
        })
        .on("click", async (e, d) => {
          let flag = false;
          this.workflow.lines.forEach(line => {
            if (!line.visible) flag = true;
          });
          
          this.workflow.lines
            .filter(line => line.smpl_workflow_line_fk)
            .forEach(line => {
              line.visible = flag;
            });
          
          // Mettre à jour les propriétés 'open' des steps
          this.steps.forEach(step => {
            const derivedLines = this.workflow.lines.filter(line => line.smpl_workflow_step_fk == step.id);
            if (derivedLines.length > 0) {
              step.open = derivedLines.every(line => line.visible);
            }
          });
          
          this.updateCounts();
        });
      
      toolbar.append("img").classed("smpllogo", true)
        .attr("src", this.resources["SMPL_logo_2"]);
    },
    
    // Mise à jour globale
    async update() {
      d3.select("#bonhommeSubject").html(this.currentEntities.subjects.length > 0 ? "Subject: " + (this.currentEntities.subjects.length > 1 ? "Multiple" : this.currentEntities.subjects[0]?.smpl_id) : "No subject selected");
      d3.select("#bonhommeCase").html(this.currentEntities.cases.length > 0 ? "Case: " + (this.currentEntities.cases.length > 1 ? "Multiple" : (this.currentEntities.cases[0]?.smpl_case_date ? this.currentEntities.cases[0]?.smpl_case_date : this.currentEntities.cases[0]?.smpl_case_id)) : "No case selected");
      
      const selectedContainers = this.currentEntities.samples
        .filter(sample => this.currentEntities.subjects.length > 0 ? this.currentEntities.subjects.find(subject => subject.id == sample.smpl_subject_fk) || !sample.smpl_subject_fk : true)
        .filter(sample => this.currentEntities.cases.length > 0 ? this.currentEntities.cases.find(casus => casus.id == sample.smpl_case_fk) || !sample.smpl_case_fk : true)
        .filter(sample => this.currentEntities.kits.length > 0 ? this.currentEntities.kits.find(kit => kit.id == sample.smpl_kit_fk) || !sample.smpl_kit_fk : true)
        .filter(sample => sample.smpl_sample_status_fk == this.getStatusId("Dispatched"));
        
      d3.select("#bonhommeContainers").html(selectedContainers?.length ? "Use selected containers (" + selectedContainers.length + ")" : "Use new containers");
      
      d3.select("#bonhommeCollect")
        .classed("active", () => {
          if (this.currentEntities.subjects.length == 0)
            return true;
          else if (this.currentEntities.subjects.length == 1 && this.currentEntities.cases.length == 0)
            return true;
          else if (this.currentEntities.subjects.length == 1 && this.currentEntities.cases.length == 1)
            return true;
          return false;
        })
        .text(() => {
          if (this.currentEntities.subjects.length == 0)
            return "New subject";
          else if (this.currentEntities.subjects.length == 1 && this.currentEntities.cases.length == 0)
            return "New case";
          else if (this.currentEntities.subjects.length == 1 && this.currentEntities.cases.length == 1)
            return "Batch collection";
        });

      this.setHorizontalPositions();
      this.setVerticalPositions();
      this.aggregateSteps();
      
      // Alignement des lots et suppression des lignes vides
      for (var i = 0; i < 15; i++) this.batchAlignment();
      this.removeEmptyRows();
      this.setVerticalPositions();
      for (var i = 0; i < 15; i++) this.batchAlignment();
      this.removeEmptyRows();
      
      // Configuration des liens et affichage
      this.setLinks();
      this.enterSteps();
      this.enterLinks();
      this.enterToolbar();
    },

    //==========================
    // GESTION DES FORMULAIRES
    //==========================
    


async loadForms() {
  this.changedFormValues = {};
  
  try {
    this.displayForms = true;
    const now = Date.now();
    
    // Utiliser Promise.all pour charger tous les formulaires en parallèle
    const formPromises = this.forms.map(async form => {
      // Vérifier si le formulaire est dans le cache et si le cache est encore valide
      const cachedTime = this.formCacheTimestamps.get(form.id);
      
      if (cachedTime && now - cachedTime < this.formCacheTTL) {
        // Utiliser la version en cache
        form.form = this.formCache.get(form.id);
      } else {
        // Récupérer le formulaire depuis le serveur
        form.form = await this.dapp.$store.dispatch('forms/fetchForm', form.id);
        
        // Mettre en cache
        this.formCache.set(form.id, form.form);
        this.formCacheTimestamps.set(form.id, now);
      }
      
      return form;
    });
    
    // Attendre que tous les formulaires soient chargés
    await Promise.all(formPromises);
  } catch (error) {
    this.exceptionHandler(error);
  } finally {
    this.loading = false;
  }
},

// Méthode pour invalider manuellement le cache des formulaires
clearFormCache() {
  this.formCache.clear();
  this.formCacheTimestamps.clear();
},

// Méthode pour invalider un formulaire spécifique
invalidateFormCache(formId) {
  this.formCache.delete(formId);
  this.formCacheTimestamps.delete(formId);
},
    
    changedValue(e, form) {
      let values = Object.assign(typeof this.changedFormValues[form.id]?.e === "object" ? this.changedFormValues[form.id].e : {}, typeof e === "object" ? e : {});
      values = Object.assign(form.defaultEntity, values);
      this.changedFormValues[form.id] = { "form": form, "e": e };
    },
    
    async updateId() {
      for (const [formId, value] of Object.entries(this.changedFormValues)) {
        const form = value.form;
        const e = value.e;
        
        if (!e?.smpl_id) Object.assign(form.defaultEntity, e);
        
        if (form?.template) {
          const entity = form.defaultEntity;
          const template = form.template;
          
          const idAssembly = await this.dapp.$axios.$post(await this.getRouteURLByName('smpl_generate_id'), { entity: entity, template: template });
          
          entity.smpl_id_stem = idAssembly.stem ? idAssembly.stem : "undefined";
          entity.smpl_id_nb = idAssembly.nb;
          entity.smpl_id = idAssembly.id;
        }
      }
    },
    
    async formSubmitted(form) {
      form.submitted = true;
      
      
      if (this.forms.every(form => form.submitted)) {
        this.$toastNotifier.notifySuccess('Form submited successfully');
        this.displayForms = false;
        await this.formDispatch();
      }
    },
    
    async submit() {
      await this.updateId();
      
      if (this.loadedForms) {
       
        
        for (let i = 0; i < this.forms.length; i++) {
          const form = this.forms[i];
          this.$refs[`formRendering${i + "" + form.id}`][0].submit();
          form.isValidForm = this.$refs[`formRendering${i + "" + form.id}`][0].isValidForm;
        }
        
        return true;
      }
      
      return false;
    },
    
    async popPipeline() {
      if (this.pipeline.length > 0) {
        this.forms = this.pipeline.pop();
        
        this.displayForms = false;
        await this.loadForms();
      }
      else {
        this.forms = [];
        
        if (this.collectionOngoing) {
          this.showCustomViewAfterSampleCreation(this.ongoingCollectionFk);
          this.collectionOngoing = false;
          this.ongoingCollectionFk = null;
        }
      }
    },

    //==========================
    // GESTION DES ENTITÉS
    //==========================
    
    selectSamplesOptimistically(step, status) {
      // Get the current status count info
      const statusInfo = status ? 
        step.count.find(c => c.status.id === status.id) : 
        { count: step.total.count, selected: step.total.selected };
      
      if (!statusInfo) return;
      
      const currentlySelected = statusInfo.selected;
      const totalCount = statusInfo.count;
      
      // Determine if we're selecting all or deselecting all
      const isSelectingAll = currentlySelected < totalCount;
      
      // Optimistically update the UI counts
      if (status) {
        // Update specific status count
        const statusCount = step.count.find(c => c.status.id === status.id);
        if (statusCount) {
          statusCount.selected = isSelectingAll ? statusCount.count : 0;
        }
      } else {
        // Update all status counts for this step
        if (step.count) {
          step.count.forEach(c => {
            c.selected = isSelectingAll ? c.count : 0;
          });
        }
      }
      
      // Update total count
      step.total.selected = isSelectingAll ? step.total.count : 0;
      
      // Return whether we're selecting or deselecting
      return isSelectingAll;
    },
    
    selectSamples(samples) {
      if (!samples || samples.length === 0) return;
      
      // Créer un Set des IDs d'échantillons déjà sélectionnés pour une recherche O(1)
      const existingIds = new Set(this.currentEntities.samples.map(s => s.id));
      
      // Filtrer uniquement les nouveaux échantillons
      const newSamples = samples.filter(sample => !existingIds.has(sample.id));
      
      // Ajouter en bloc
      if (newSamples.length > 0) {
        this.currentEntities.samples = [...this.currentEntities.samples, ...newSamples];
        // Déclencher updateCounts une seule fois à la fin
        this.$nextTick(() => this.updateCounts());
      }
    },
    
    unselectSamples(samples) {
      if (!samples) {
        this.currentEntities.samples = [];
        this.updateCounts();
        return;
      }
      
      for (let i = this.currentEntities.samples.length - 1; i >= 0; i--) {
        const found = samples.find(sample => sample.id == this.currentEntities.samples[i].id);
        if (found) this.currentEntities.samples.splice(i, 1);
      }
      
      this.updateCounts();
    },
    
    unselectSampleIds(ids) {
      if (!ids) {
        this.currentEntities.samples = [];
        this.updateCounts();
        return;
      }
      
      for (let i = this.currentEntities.samples.length - 1; i >= 0; i--) {
        const found = ids.find(id => id == this.currentEntities.samples[i].id);
        if (found) this.currentEntities.samples.splice(i, 1);
      }
      
      this.updateCounts();
    },
    
    selectSubjects(subjects, toBeUnselectedIds = []) {
      this.currentEntities.subjects = this.currentEntities.subjects.filter(subject => !toBeUnselectedIds.includes(subject.id));
      this.currentEntities.cases = this.currentEntities.cases.filter(casus => !toBeUnselectedIds.includes(casus.smpl_subject_fk));
      
      if (subjects) {
        this.currentEntities.subjects = subjects;
        this.updateCounts();
      }
    },
    
    async selectCases(cases, toBeUnselectedIds = []) {
      this.currentEntities.cases = this.currentEntities.cases.filter(casus => !toBeUnselectedIds.includes(casus.id));

      if (cases) {
        // Refetch depuis le serveur pour garantir l'id DB sur chaque case
        let fetchedCases = [];
        for (const casus of cases) {
          if (casus?.id) {
            const fetched = await this.getEntity(casus.id);
            fetchedCases.push(fetched || casus);
          } else {
            fetchedCases.push(casus);
          }
        }
        this.currentEntities.cases = fetchedCases;

        let subjectIds = [];
        fetchedCases.forEach(casus => {
          if (casus?.smpl_subject_fk) subjectIds.push(casus.smpl_subject_fk);
        });

        if (subjectIds.length > 0) {
          let subjects = [];
          for (const subjectId of subjectIds) {
            subjects.push(await this.getEntity(subjectId));
          }
          this.selectSubjects(subjects);
        } else {
          this.updateCounts();
        }
      }
    },
    
    async selectKit(kitId) {
      if (this.workflow?.smpl_workflow_uses_kits) {
        const kit = await this.getEntity(kitId);

        if (kit.smpl_subject_fk) {
          this.currentEntities.subjects = [await this.getEntity(kit.smpl_subject_fk)];
        }
        
        if (kit.smpl_case_fk) {
          this.currentEntities.cases = [await this.getEntity(kit.smpl_case_fk)];
        }

        const samples = await this.getSamplesByKit(kitId);
        this.selectSamples(samples);
        this.updateCounts();
      }
    },
    
    onEntitiesSelected(entities) {
  this.toBeSelected = entities;
},

    //==========================
    // GESTION DES VUES PERSONNALISÉES
    //==========================
    
customviewSelect() {
  // Traiter selon le type d'entité directement avec toBeSelected
  if (!this.customView) {
    return;
  }

  if (this.customView.entitytype_id == this.getEntityType("SMPL_SAMPLE").id) {
    const newSelectedIds = new Set(this.toBeSelected.map(e => e.id));
    const toDeselectIds = this.selectedCustomViewEntitiesIds.filter(id => !newSelectedIds.has(id));
    if (toDeselectIds.length > 0) this.unselectSampleIds(toDeselectIds);
    this.selectSamples(this.toBeSelected);
  } else if (this.customView.entitytype_id == this.getEntityType("SMPL_SUBJECT").id) {
    this.selectSubjects(this.toBeSelected);
  } else if (this.customView.entitytype_id == this.getEntityType("SMPL_CASE").id) {
    this.selectCases(this.toBeSelected);
  } else if (this.customView.entitytype_id == this.getEntityType("SMPL_KIT").id) {
    this.toBeSelected.forEach(async kit => {
      const samples = await this.getSamplesByKit(kit.id);
      this.selectSamples(samples);
    });
  }

  this.closeCustomview();
},
    
    closeCustomview() {
      this.customViewData.type = null;
      this.customView = null;
      this.reloadWorkflow();
    },
    
    closeForm() {
      this.currentForm = null;
      this.pipeline = [];
    },
    
    getFieldId(name) {
      const field = this.$store.state.fields.fields.find(field => field.name == name);
      return field.id;
    },
    
cleanTimestamps(obj) {
  if (Array.isArray(obj)) {
    return obj.map(item => this.cleanTimestamps(item));
  } else if (obj !== null && typeof obj === 'object') {
    const { created_at, updated_at, ...rest } = obj;
    const cleaned = {};
    for (const key in rest) {
      cleaned[key] = this.cleanTimestamps(rest[key]);
    }
    return cleaned;
  }
  return obj;
},

async showCustomViewAfterSampleCreation(smpl_collection_fk) {
  this.selectedCustomViewEntitiesIds = this.currentEntities.samples.map(entity => entity.id);

  const entity = await this.getEntity(smpl_collection_fk);
  const step = entity.smpl_workflow_step_fk ? this.getStep(entity.smpl_workflow_step_fk) : null;
  const line = step ? this.getLine(step.smpl_workflow_line_fk) : null;

  this.customViewData.title = (step ? line.smpl_label + "/" + step.type : "Collection") + " - New samples";

  let field;

  if (entity.entitytype_id == this.getEntityType("SMPL_COLLECTION").id) field = "smpl_collection_fk";
  else if (entity.entitytype_id == this.getEntityType("SMPL_DERIVATION").id) field = "smpl_derivation_fk";
  else field = "smpl_kit_fk";
  
  const operand = this.getFieldId(field);
  
  try {
    const currentCustomView = await this.dapp.$axios.$get(`/customviews/${this.customViewIds.samples}`);
    const cleanedView = this.cleanTimestamps(currentCustomView);
    const { entitytype_id, entitytype, ...cleanCustomView } = cleanedView;
    
    const existingFilters = cleanCustomView.filters || { type: "bracket", operationCode: "&&", conditions: [] };
    const conditions = existingFilters.conditions.filter(cond => {
      if (cond.type === "condition" && cond.operand === operand) return false;
      return true;
    });
    
    conditions.unshift({
      type: "condition",
      operationCode: "=",
      operand: operand,
      value: smpl_collection_fk
    });
    
    const filters = {
      ...existingFilters,
      conditions: conditions
    };
    
    const updateData = { ...cleanCustomView, filters: filters };
    
    await this.dapp.$axios.$put(`/customviews/${this.customViewIds.samples}`, updateData);
    this.customViewData.type = "SMPL_SAMPLE";
    this.customView = await this.dapp.$axios.$get(`/customviews/${this.customViewIds.samples}`);
  } catch (e) {
  } finally {
    this.loading = false;
  }
},

async showCustomViewForStep(stepId = null, smpl_sample_status_fk = null) {
  const scopeSamples = stepId
    ? this.currentEntities.samples.filter(s => s.smpl_workflow_step_fk === stepId)
    : this.currentEntities.samples;
  this.selectedCustomViewEntitiesIds = scopeSamples.map(entity => entity.id);

  let customViewId = this.customViewIds.samples;
  this.customViewData.title = "";
  
  if (stepId) {
    const step = this.getStep(stepId);
    if (!step) {
      return;
    }
    
    const line = this.getLine(step.smpl_workflow_line_fk);
    
    if (line?.smpl_custom_view_id) {
      customViewId = line.smpl_custom_view_id;
    }
    
    const breadcrumbs = this.generateBreadCrumbs(step);
    let title = breadcrumbs
      .map(crumb => crumb?.smpl_label ? crumb.smpl_label : crumb.type)
      .join("/");
    
    this.customViewData.title = title;
  }
  
  try {
    const currentCustomView = await this.dapp.$axios.$get(`/customviews/${customViewId}`);
    const cleanedView = this.cleanTimestamps(currentCustomView);
    const { entitytype_id, entitytype, ...cleanCustomView } = cleanedView;
    
    const existingFilters = cleanCustomView.filters || { type: "bracket", operationCode: "&&", conditions: [] };
    
    const statusFieldId = this.getFieldId("smpl_sample_status_fk");
    const stepFieldId = this.getFieldId("smpl_workflow_step_fk");
    const subjectFieldId = this.getFieldId("smpl_subject_fk");
    const caseFieldId = this.getFieldId("smpl_case_fk");
    
    const conditions = existingFilters.conditions.filter(cond => {
      if (cond.type === "condition") {
        if (cond.operand === statusFieldId) return false;
        if (cond.operand === stepFieldId) return false;
        if (cond.operand === subjectFieldId) return false;
        if (cond.operand === caseFieldId) return false;
      }
      return true;
    });
    
    if (smpl_sample_status_fk) {
      conditions.push({
        type: "condition",
        operationCode: "=",
        operand: statusFieldId,
        value: smpl_sample_status_fk
      });
    } else {
      conditions.push({
        type: "condition",
        operationCode: "in",
        operand: statusFieldId,
        value: this.workflow.statuses.filter(status => status.smpl_status_is_active).map(entry => entry.id)
      });
    }
    
    if (stepId) {
      conditions.push({
        type: "condition",
        operationCode: "=",
        operand: stepFieldId,
        value: stepId
      });
    }
    
    if (this.currentEntities.subjects.length > 0) {
      conditions.push({
        type: "condition",
        operationCode: "in",
        operand: subjectFieldId,
        value: this.currentEntities.subjects.map(entry => entry.id)
      });
    }
    
    if (this.currentEntities.cases.length > 0) {
      conditions.push({
        type: "condition",
        operationCode: "in",
        operand: caseFieldId,
        value: this.currentEntities.cases.map(entry => entry.id)
      });
    }
    
    const filters = {
      ...existingFilters,
      conditions: conditions
    };
    
    const updateData = { ...cleanCustomView, filters: filters };
    
    await this.dapp.$axios.$put(`/customviews/${customViewId}`, updateData);
    this.customView = await this.dapp.$axios.$get(`/customviews/${customViewId}`);
    this.customViewData.type = "SMPL_SAMPLE";
  } catch (error) {
  }
},

async showCustomViewForSubjects() {
  let customViewId = this.customViewIds.subjects;
  this.customViewTitle = "Subjects";

  this.selectedCustomViewEntitiesIds = this.currentEntities.subjects.map(entry => entry.id);
  
  try {
    const currentCustomView = await this.dapp.$axios.$get(`/customviews/${customViewId}`);
    const cleanedView = this.cleanTimestamps(currentCustomView);
    const { entitytype_id, entitytype, ...cleanCustomView } = cleanedView;
    
    const existingFilters = cleanCustomView.filters || { type: "bracket", operationCode: "&&", conditions: [] };
    const studyFieldId = this.getFieldId("smpl_study_fk");
    
    const conditions = existingFilters.conditions.filter(cond => {
      if (cond.type === "condition" && cond.operand === studyFieldId) return false;
      return true;
    });
    
    conditions.unshift({
      type: "condition",
      operationCode: "=",
      operand: studyFieldId,
      value: this.workflow.smpl_study_fk
    });
    
    const filters = {
      ...existingFilters,
      conditions: conditions
    };
    
    const updateData = { ...cleanCustomView, filters: filters };
    
    await this.dapp.$axios.$put(`/customviews/${customViewId}`, updateData);
    this.customView = await this.dapp.$axios.$get(`/customviews/${customViewId}`);
  } catch (e) {
  } finally {
    this.loading = false;
  }
},

async showCustomViewForCases() {
  let customViewId = this.customViewIds.cases;
  this.customViewTitle = "Cases";

  this.selectedCustomViewEntitiesIds = this.currentEntities.cases.map(entry => entry.id);
  
  try {
    const currentCustomView = await this.dapp.$axios.$get(`/customviews/${customViewId}`);
    const cleanedView = this.cleanTimestamps(currentCustomView);
    const { entitytype_id, entitytype, ...cleanCustomView } = cleanedView;
    
    const existingFilters = cleanCustomView.filters || { type: "bracket", operationCode: "&&", conditions: [] };
    const studyFieldId = this.getFieldId("smpl_study_fk");
    const subjectFieldId = this.getFieldId("smpl_subject_fk");
    
    const conditions = existingFilters.conditions.filter(cond => {
      if (cond.type === "condition") {
        if (cond.operand === studyFieldId) return false;
        if (cond.operand === subjectFieldId) return false;
      }
      return true;
    });
    
    conditions.unshift({
      type: "condition",
      operationCode: "=",
      operand: studyFieldId,
      value: this.workflow.smpl_study_fk
    });
    
    if (this.currentEntities.subjects.length > 0) {
      conditions.push({
        type: "condition",
        operationCode: "in",
        operand: subjectFieldId,
        value: this.currentEntities.subjects.map(subject => subject.id)
      });
    }
    
    const filters = {
      ...existingFilters,
      conditions: conditions
    };
    
    const updateData = { ...cleanCustomView, filters: filters };
    
    await this.dapp.$axios.$put(`/customviews/${customViewId}`, updateData);
    this.customView = await this.dapp.$axios.$get(`/customviews/${customViewId}`);
  } catch (e) {
  } finally {
    this.loading = false;
  }
},

async showCustomViewForKits() {
  let customViewId = this.customViewIds.kits;
  this.customViewTitle = "Kits";

  this.selectedCustomViewEntitiesIds = this.currentEntities.kits.map(entry => entry.id);
  
  try {
    const currentCustomView = await this.dapp.$axios.$get(`/customviews/${customViewId}`);
    const cleanedView = this.cleanTimestamps(currentCustomView);
    const { entitytype_id, entitytype, ...cleanCustomView } = cleanedView;
    
    const existingFilters = cleanCustomView.filters || { type: "bracket", operationCode: "&&", conditions: [] };
    const subjectFieldId = this.getFieldId("smpl_subject_fk");
    const caseFieldId = this.getFieldId("smpl_case_fk");
    
    const conditions = existingFilters.conditions.filter(cond => {
      if (cond.type === "condition") {
        if (cond.operand === subjectFieldId) return false;
        if (cond.operand === caseFieldId) return false;
      }
      return true;
    });
    
    if (this.currentEntities.subjects.length > 0) {
      conditions.push({
        type: "condition",
        operationCode: "in",
        operand: subjectFieldId,
        value: this.currentEntities.subjects.map(subject => subject.id)
      });
    }
    
    if (this.currentEntities.cases.length > 0) {
      conditions.push({
        type: "condition",
        operationCode: "in",
        operand: caseFieldId,
        value: this.currentEntities.cases.map(casus => casus.id)
      });
    }
    
    const filters = {
      ...existingFilters,
      conditions: conditions
    };
    
    const updateData = { ...cleanCustomView, filters: filters };
    
    await this.dapp.$axios.$put(`/customviews/${customViewId}`, updateData);
    this.customView = await this.dapp.$axios.$get(`/customviews/${customViewId}`);
  } catch (e) {
  } finally {
    this.loading = false;
  }
},
    
    generateBreadCrumbs(entity, breadcrumbs = []) {
      breadcrumbs.unshift(entity);
      if (entity.smpl_workflow_line_fk) {
        this.generateBreadCrumbs(this.getLine(entity.smpl_workflow_line_fk), breadcrumbs);
      }
      return breadcrumbs;
    },

    //==========================
    // GESTION DES ÉVÉNEMENTS
    //==========================
    
    async scan(barcode) {
      let uri = await this.getRouteURLByName('smpl_get_entity_by_barcode');
      uri += '&barcode=' + barcode;
      const response = await this.dapp.$axios.$get(uri);
      
      if (response && response[0]) {
        const entity = response[0];
        
        if (entity.entitytype_id == this.getEntityType("SMPL_SAMPLE").id) {
          this.selectSamples([entity]);
          this.$toastNotifier.notifySuccess('Entity selected');
        }
        else if (entity.entitytype_id == this.getEntityType("SMPL_KIT").id) {
          await this.selectKit(entity.id);
          this.$toastNotifier.notifySuccess('Kit selected');
        }
        else if (entity.entitytype_id == this.getEntityType("SMPL_CASE").id) {
          const caseTranslation = this.$translate('field', 'label', this.getFieldByName('smpl_case_fk'));
          this.$toastNotifier.notifySuccess(caseTranslation + ' selected');
        }
        else if (entity.entitytype_id == this.getEntityType("SMPL_SUBJECT").id) {
          this.$toastNotifier.notifySuccess('Subject selected');
        }
        
        this.updateCounts();
      }
    },
    
    async centerOn(entity) {
      this.generateBreadCrumbs(entity).forEach((crumb, i) => {
        if (crumb?.visible == false) crumb.visible = true;
      });
      
      await this.updateCounts();
      
      d3.select('svg').transition().call(this.zoom.transform, d3.zoomIdentity.translate(- entity.x * this.grid.step[0] * 2 + this.width / 3, (entity.y ? - entity.y * this.grid.step[1] * 2 + this.height / 3 : -entity.steps[0].y * this.grid.step[1] * 2 + this.height / 3)).scale(2));
    },
    
    getPrevSteps(step) {
      let prevSteps = [];
      const line = this.workflow.lines.find(line => line.id == step.smpl_workflow_line_fk);
      let flag = true;
      let order = step.smpl_order - 1;
      
      while (flag) {
        const ps = line.steps.find(s => s.smpl_order == order);
        if (!ps) break;
        
        prevSteps.push(ps.id);
        if (ps?.smpl_workflow_step_batch_fk) prevSteps.push(ps.smpl_workflow_step_batch_fk);
        
        if (!ps.smpl_workflow_step_is_optional && ps.type != "GoTo") break;
        order--;
      }
      
      return prevSteps;
    },

    //==========================
    // GESTION DES COLLECTIONS
    //==========================
    
    getPrecollectionSamples() {
      return this.currentEntities.samples
        .filter(sample => this.currentEntities.subjects.length > 0 ? this.currentEntities.subjects.find(subject => subject.id == sample.smpl_subject_fk) || !sample.smpl_subject_fk : true)
        .filter(sample => this.currentEntities.cases.length > 0 ? this.currentEntities.cases.find(casus => casus.id == sample.smpl_case_fk) || !sample.smpl_case_fk : true)
        .filter(sample => this.currentEntities.kits.length > 0 ? this.currentEntities.kits.find(kit => kit.id == sample.smpl_kit_fk) || !sample.smpl_kit_fk : true)
        .filter(sample => sample.smpl_sample_status_fk == this.getStatusId("Dispatched"));
    },
    
    async startCollection() {
      this.collectionOngoing = true;
      
      if (this.currentEntities.subjects.length == 0) {
        await this.promptNewSubject();
      }
      else if (this.currentEntities.cases.length == 0) {
        await this.promptNewCase();
      }
      else {
        const selectedContainers = this.currentEntities.samples
          .filter(sample => this.currentEntities.subjects.length > 0 ? this.currentEntities.subjects.find(subject => subject.id == sample.smpl_subject_fk) || !sample.smpl_subject_fk : true)
          .filter(sample => this.currentEntities.cases.length > 0 ? this.currentEntities.cases.find(casus => casus.id == sample.smpl_case_fk) || !sample.smpl_case_fk : true)
          .filter(sample => this.currentEntities.kits.length > 0 ? this.currentEntities.kits.find(kit => kit.id == sample.smpl_kit_fk) || !sample.smpl_kit_fk : true)
          .filter(sample => sample.smpl_sample_status_fk == this.getStatusId("Dispatched"));
          
        if (selectedContainers.length) {
          this.promptNewCollection(selectedContainers);
        }
        else {
          await this.promptNewKit();
        }
      }
      
      this.popPipeline();
    },
    
    async startKitCreation() {
      await this.promptNewKit(false, true);
      this.popPipeline();
    },
    
    async promptNewSubject() {
      this.closeCustomview();

      let entity = {
        entitytype_id: this.getEntityType("SMPL_SUBJECT").id,
        smpl_study_fk: this.workflow.smpl_study_fk
      };

      let template;
      if (this.workflow.smpl_subject_id_gen_fk) {
        template = await this.getEntity(this.workflow.smpl_subject_id_gen_fk);
        const idAssembly = await this.dapp.$axios.$post(await this.getRouteURLByName('smpl_generate_id'), { entity: entity, template: template });
      
        entity.smpl_id_stem = idAssembly.stem ? idAssembly.stem : "undefined";
        entity.smpl_id_nb = idAssembly.nb;
        entity.smpl_id = idAssembly.id;
      }

      const forms = [{
        id: this.workflow.smpl_workflow_subject_form_id, form: null, isValidForm: true,
        title: this.collectionOngoing ? "Collection - New subject" : "New subject",
        defaultEntity: entity,
        template: template
      }];
      
      this.pipeline.push(forms);
      this.popPipeline();
    },
    
    async promptNewCase() {
      this.closeCustomview();

      let entity = {
        entitytype_id: this.getEntityType("SMPL_CASE").id,
        smpl_subject_fk: this.currentEntities.subjects[0]?.id,
        smpl_study_fk: this.workflow.smpl_study_fk,
      };

      let template;
      if (this.workflow.smpl_case_id_gen_fk) {
        template = await this.getEntity(this.workflow.smpl_case_id_gen_fk);
        const idAssembly = await this.dapp.$axios.$post(await this.getRouteURLByName('smpl_generate_id'), { entity: entity, template: template });
        
        entity.smpl_id_stem = idAssembly.stem ? idAssembly.stem : "undefined";
        entity.smpl_id_nb = idAssembly.nb;
        entity.smpl_id = idAssembly.id;
      }

      const forms = [{
        id: this.workflow.smpl_workflow_case_form_id, form: null, isValidForm: true,
        title: (this.collectionOngoing ? "Collection - " : "") + "New case for subject " + this.currentEntities.subjects[0]?.smpl_id,
        defaultEntity: entity,
        template: template
      }];
      
      this.pipeline.push(forms);
      this.popPipeline();
    },
    
    async promptNewKit(collection = true, isReal = false) {
      let entity = {
        entitytype_id: this.getEntityType("SMPL_KIT").id,
        smpl_kit_is_real: isReal,
        smpl_subject_fk: this.currentEntities.subjects[0]?.id,
        smpl_case_fk: this.currentEntities.cases[0]?.id,
      };

      let template;
      if (this.workflow.smpl_kit_id_gen_fk) {
        template = await this.getEntity(this.workflow.smpl_kit_id_gen_fk);
        const idAssembly = await this.dapp.$axios.$post(await this.getRouteURLByName('smpl_generate_id'), { entity: entity, template: template });
        
        entity.smpl_id_stem = idAssembly.stem ? idAssembly.stem : "undefined";
        entity.smpl_id_nb = idAssembly.nb;
        entity.smpl_id = idAssembly.id;
      }

      const forms = [{
        id: this.workflow.smpl_workflow_kit_form_id, form: null, isValidForm: true,
        title: collection ? "Collection information" : "Kit content",
        defaultEntity: entity,
        template: template
      }];

      if (collection) {
        forms.push({
          id: this.getEventTypeByName("Collection").smpl_template_form_id, form: null, isValidForm: true,
          defaultEntity: {
            entitytype_id: this.getEntityType("SMPL_EVENT").id,
            smpl_event_type_fk: this.getEventTypeByName("Collection").id,
            smpl_subject_fk: this.currentEntities.subjects[0]?.id,
            smpl_case_fk: this.currentEntities.cases[0]?.id,
            SMPL_CREATION_with_collection: true
          }
        });
      }

      this.workflow.lines.filter(line => line.smpl_workflow_line_is_kit).forEach((line, i) => {
        forms.push({
          id: this.sampleCreationFormId,
          form: null, isValidForm: true,
          defaultEntity: {
            entitytype_id: this.getEntityType("SMPL_CREATION").id,
            smpl_workflow_line_fk: line.id,
            SMPL_CREATION_with_container: true,
            SMPL_CREATION_with_collection: collection,
            smpl_workflow_line_quantity: line.smpl_workflow_line_quantity,
            smpl_subject_fk: this.currentEntities.subjects[0]?.id
          }
        });
      });
      
      this.pipeline.push(forms);
    },
    
    promptNewCollection(samples) {
      const sampleIds = samples.map(sample => sample.id);
      
      const forms = [{
        id: this.getEventTypeByName("Collection").smpl_template_form_id, form: null, isValidForm: true,
        title: "Collection of " + samples.length + " sample" + (samples.length > 1 ? "s" : ""),
        defaultEntity: {
          entitytype_id: this.getEntityType("SMPL_EVENT").id,
          smpl_event_type_fk: this.getEventTypeByName("Collection").id,
          smpl_samples_fk: sampleIds
        }
      }];
      
      this.pipeline.push(forms);
    },
    
    standardBatchCollection() {
      const subjectId = this.currentEntities.subjects[0].id;
      const caseId = this.currentEntities.cases[0].id;

      let forms = [{
        id: this.getEventTypeByName("Collection").smpl_template_form_id, form: null, isValidForm: true,
        defaultEntity: {
          entitytype_id: this.getEntityType("SMPL_EVENT").id,
          smpl_event_type_fk: this.getEventTypeByName("Collection").id,
          smpl_subject_fk: subjectId,
          smpl_case_fk: caseId,
        }
      }];

      this.workflow.lines.filter(line => line.smpl_workflow_line_is_kit == true).forEach(line => {
        forms.push({
          id: this.sampleCreationFormId,
          form: null, isValidForm: true,
          defaultEntity: {
            entitytype_id: this.getEntityType("SMPL_CREATION").id,
            smpl_workflow_line_fk: line.id,
            smpl_sample_creation_with_collection: true,
            smpl_workflow_line_quantity: line.smpl_workflow_line_quantity,
            smpl_subject_fk: subjectId
          }
        });
      });

      this.pipeline.push(forms);
      this.popPipeline();
    },

    standardLineCollection(line) {
      const subjectId = this.currentEntities.subjects[0].id;
      const caseId = this.currentEntities.cases[0].id;

      const forms = [
        {
          id: this.getEventTypeByName("Collection").smpl_template_form_id, form: null, isValidForm: true,
          defaultEntity: {
            entitytype_id: this.getEntityType("SMPL_EVENT").id,
            smpl_event_type_fk: this.getEventTypeByName("Collection").id,
            smpl_subject_fk: subjectId,
            smpl_case_fk: caseId,
          }
        },
        {
          id: this.sampleCreationFormId,
          form: null, isValidForm: true,
          defaultEntity: {
            entitytype_id: this.getEntityType("SMPL_CREATION").id,
            smpl_workflow_line_fk: line.id,
            smpl_sample_creation_with_collection: true,
            smpl_workflow_line_quantity: line.smpl_workflow_line_quantity,
            smpl_subject_fk: subjectId
          }
        }
      ];

      this.pipeline.push(forms);
      this.popPipeline();
    },
    
    standardEvent(step) {
      let eventType = this.getEventTypeById(step.smpl_event_type_fk);
      
      if (eventType.smpl_label == "Collection") {
        this.standardLineCollection(this.getLine(step.smpl_workflow_line_fk));
        return;
      }
      
      let aliasStep = step;
      let actualStep = step;
      
      if (eventType.smpl_is_alias) {
        actualStep = this.getStep(step.smpl_workflow_step_goto_fk);
        eventType = this.getEventTypeById(actualStep.smpl_event_type_fk);
      }

      let batchStep = null;
      
      if (actualStep?.smpl_workflow_step_is_batch) {
        batchStep = actualStep;
      } else if (actualStep?.smpl_workflow_step_batch_fk) {
        batchStep = this.getBatch(actualStep.smpl_workflow_step_batch_fk);
      }

      let forms = [{
        id: batchStep ? batchStep.smpl_step_form_id : actualStep.smpl_workflow_step_form_id, form: null, isValidForm: true,
        title: "New " + (actualStep?.type ? actualStep.type : "Event"),
        defaultEntity: {
          entitytype_id: this.getEntityType("SMPL_EVENT").id,
          smpl_workflow_step_fk: actualStep.id,
          smpl_event_type_fk: eventType.id,
          smpl_workflow_step_alias_fk: batchStep ? null : aliasStep.id
        }
      }];

      if (eventType.smpl_yields_derivative && !actualStep.smpl_workflow_step_is_batch) {
        this.workflow.lines.filter(line => line.smpl_workflow_step_fk == actualStep.id).forEach((line, i) => {
          forms.push({
            id: this.sampleCreationFormId, form: null, isValidForm: true,
            defaultEntity: {
              entitytype_id: this.getEntityType("SMPL_CREATION").id,
              smpl_workflow_line_fk: line.id,
              SMPL_CREATION_with_collection: true,
              smpl_workflow_line_quantity: line.smpl_workflow_line_quantity,
              smpl_order: i
            }
          });
        });
      }

      this.pipeline.unshift(forms);
      this.popPipeline();
    },

    //==========================
    // GESTION DES OPÉRATIONS SUR LES ENTITÉS
    //==========================
    
    async formDispatch() {
      const formData = this.forms.map(form => form.e);

      let subjectData = formData.filter(data => data.entitytype_id == this.getEntityType("SMPL_SUBJECT").id);
      let caseData = formData.filter(data => data.entitytype_id == this.getEntityType("SMPL_CASE").id);

      // Données de collection
      let collectionData = formData
        .filter(data => data.entitytype_id == this.getEntityType("SMPL_EVENT").id)
        .find(data => {
          const eventType = this.getEventTypeById(data.smpl_event_type_fk);
          return eventType?.smpl_label == "Collection";
        });
        
      let derivationData = formData.find(data => {
        return this.getEventTypeById(data.smpl_event_type_fk)?.smpl_yields_derivative;
      });
      
      let storageData = formData
        .filter(data => data.entitytype_id == this.getEntityType("SMPL_EVENT").id)
        .find(data => data?.STORAGE);
        
      const sampleCreation = formData.filter(data => data.entitytype_id == this.getEntityType("SMPL_CREATION").id);

      if (subjectData.length > 0) {
        this.selectSubjects([subjectData[0]]);
      }
      else if (caseData.length > 0) {
        this.selectCases([caseData[0]]);
      }
      else if (collectionData) {
        collectionData.smpl_subject_fk = this.currentEntities.subjects[0]?.id;
        collectionData.smpl_case_fk = this.currentEntities.cases[0]?.id;
        collectionData.smpl_kit_fk = this.currentEntities.kits[0]?.id;

        if (sampleCreation.length > 0) {
          await this.newSpecimens(sampleCreation, collectionData);
        }
      }
      else {
        if (storageData) {
          await this.dapp.$axios.$put(`/entities/${storageData.id}`, { 
            "STORAGE": null, 
            "POSITION_COLUMN": null, 
            "POSITION_ROW": null 
          });
        }

        if (derivationData) {
          if (sampleCreation.length > 0) {
            for (let i = 0; i < sampleCreation.length; i++) {
              const ticket = sampleCreation[i];
              await this.newDerivatives(ticket, derivationData);
            }
          }
        }
        
        for (const form of this.forms.filter(f => f.e?.smpl_event_type_fk)) {
          const data = { ...form.e };

          for (const [key, value] of Object.entries(form.defaultEntity || {})) {
            if ((value === null || value === '' || value === undefined) && !(key in data)) {
              data[key] = null;
            }
          }

          const changedData = this.changedFormValues[form.id]?.e || {};
          for (const [key, value] of Object.entries(changedData)) {
            if (value === null || value === '' || value === undefined) {
              data[key] = null;
            }
          }

          const step = this.getStep(data.smpl_workflow_step_fk);

          if (step?.smpl_workflow_step_is_batch) {
            const steps = step.steps;
            for (let i = 0; i < steps.length; i++) {
              await this.sampleEventUpdate(Object.assign({}, data, { smpl_workflow_step_fk: steps[i].id }));
            }
          } else {
            await this.sampleEventUpdate(data);
          }
        }
      }

      this.reloadWorkflow();
      this.popPipeline();
    },
    
    async newSpecimens(tickets, data) {
      let createData = [];
      
      for (let j = 0; j < tickets.length; j++) {
        const ticket = tickets[j];
        const line = this.getLine(ticket.smpl_workflow_line_fk);
        const barcodes = ticket.smpl_barcodes ? ticket.smpl_barcodes.split(/[\s,\r\n\t]+/) : null;
        const quantity = ticket.smpl_workflow_line_quantity;
        
        for (let i = 0; i < (barcodes ? barcodes.length : quantity); i++) {
          const entity = {
            entitytype_id: this.getEntityType("SMPL_SAMPLE").id,
            smpl_events_fk: [data.id],
            BARCODE: (barcodes ? barcodes[i] : null),
            smpl_subject_fk: data?.smpl_subject_fk,
            smpl_case_fk: data?.smpl_case_fk,
            smpl_kit_fk: data.smpl_kit_fk,
            smpl_study_fk: this.workflow.smpl_study_fk,
            smpl_workflow_fk: this.workflow.id,
            smpl_workflow_line_fk: line.id,
            smpl_container_type_fk: line?.smpl_container_type_fk,
            smpl_container_volume: line?.smpl_container_volume,
            smpl_sample_type_fk: line?.smpl_sample_type_fk,
            smpl_content_volume: line?.smpl_content_volume ? line.smpl_content_volume : null,
            smpl_volume_unit: line?.smpl_volume_unit ? line?.smpl_volume_unit : this.getChoiceId("smpl_volume_unit_category", "ml"),
            smpl_collection_start_time: ticket.smpl_sample_creation_with_collection ? data?.smpl_event_start_time : null,
            smpl_sample_status_fk: ticket.smpl_sample_creation_with_collection ? this.getStatusId("Collected") : this.getStatusId("Created"),
            smpl_workflow_step_fk: ticket.smpl_sample_creation_with_collection ? line.steps[1].id : line.steps[0].id,
            smpl_order: i
          };
          
          if (line.smpl_id_gen_fk) {
            const template = await this.getEntity(line.smpl_id_gen_fk);
            const idAssembly = await this.dapp.$axios.$post(await this.getRouteURLByName('smpl_generate_id'), { entity: entity, template: template });
            
            entity.smpl_id_stem = idAssembly.stem ? idAssembly.stem : "undefined";
            entity.smpl_id_nb = idAssembly.nb;
            entity.smpl_id = idAssembly.id;
          }

          createData.push(entity);
        }
      }
      
      await this.batchCreate(createData);
      await this.batchDelete(tickets);
    },
    
    async newDerivatives(ticket, data) {
      const step = this.getStep(data.smpl_workflow_step_fk);
      const line = this.getLine(ticket.smpl_workflow_line_fk);
      const barcodes = ticket.smpl_barcodes ? ticket.smpl_barcodes.split(/[\s,\r\n\t]+/) : null;
      const quantity = ticket.smpl_workflow_line_quantity;
      
      let samples = this.currentEntities.samples
        .filter(sample => step.prevSteps.includes(sample.smpl_workflow_step_fk))
        .filter(sample => this.currentEntities.subjects.length > 0 ? this.currentEntities.subjects.find(subject => subject.id == sample.smpl_subject_fk) || !sample.smpl_subject_fk : true)
        .filter(sample => this.currentEntities.cases.length > 0 ? this.currentEntities.cases.find(casus => casus.id == sample.smpl_case_fk) || !sample.smpl_case_fk : true)
        .filter(sample => this.currentEntities.kits.length > 0 ? this.currentEntities.kits.find(kit => kit.id == sample.smpl_kit_fk) || !sample.smpl_kit_fk : true);

      let createData = [];
      
      for (let j = 0; j < samples.length; j++) {
        const parentSample = samples[j];
        for (let i = 0; i < quantity; i++) {
          const entity = {
            entitytype_id: this.getEntityType("SMPL_SAMPLE").id,
            smpl_events_fk: data.smpl_inherits ? parentSample.smpl_events_fk : [data.id],
            BARCODE: barcodes ? (barcodes[j * quantity + i] || null) : null,
            smpl_subject_fk: parentSample?.smpl_subject_fk,
            smpl_case_fk: parentSample?.smpl_case_fk,
            smpl_kit_fk: parentSample?.smpl_kit_fk,
            smpl_study_fk: this.workflow.smpl_study_fk,
            smpl_workflow_fk: this.workflow.id,
            smpl_workflow_line_fk: line.id,
            smpl_sample_fk: parentSample.id,
            smpl_container_type_fk: line?.smpl_container_type_fk,
            smpl_container_volume: line?.smpl_container_volume,
            smpl_sample_type_fk: line?.smpl_sample_type_fk,
            smpl_content_volume: line?.smpl_content_volume ? line.smpl_content_volume : null,
            smpl_volume_unit: line?.smpl_volume_unit ? line?.smpl_volume_unit : this.getChoiceId("smpl_volume_unit_category", "ml"),
            smpl_collection_start_time: parentSample?.smpl_collection_start_time,
            smpl_sample_status_fk: this.getStatusId("Allocated"),
            smpl_workflow_step_fk: line.steps[1].id,
            smpl_order: i
          };

          if (line.smpl_id_gen_fk) {
            const template = await this.getEntity(line.smpl_id_gen_fk);
            const idAssembly = await this.dapp.$axios.$post(await this.getRouteURLByName('smpl_generate_id'), { entity: entity, template: template });

            entity.smpl_id_stem = idAssembly.stem ? idAssembly.stem : "undefined";
            entity.smpl_id_nb = idAssembly.nb;
            entity.smpl_id = idAssembly.id;
          }

          createData.push(entity);
        }
      }
      
      await this.batchCreate(createData);
    },
    
    async sampleEventUpdate(data) {
      let step = this.getStep(data.smpl_workflow_step_fk);

      let unit, consumedVolumeInMl = 0;
      if (data.smpl_consumed_volume && data.smpl_volume_unit) {
        unit = this.getChoiceDescription(data.smpl_volume_unit);
        consumedVolumeInMl = data.smpl_consumed_volume * unit;
      }

      const sampleStep = data?.smpl_workflow_step_alias_fk ? this.getStep(data.smpl_workflow_step_alias_fk) : step;

      let samples = this.currentEntities.samples
        .filter(sample => sampleStep.prevSteps.includes(sample.smpl_workflow_step_fk))
        .filter(sample => this.currentEntities.subjects.length > 0 ? this.currentEntities.subjects.find(subject => subject.id == sample.smpl_subject_fk) || !sample.smpl_subject_fk : true)
        .filter(sample => this.currentEntities.cases.length > 0 ? this.currentEntities.cases.find(casus => casus.id == sample.smpl_case_fk) || !sample.smpl_case_fk : true)
        .filter(sample => this.currentEntities.kits.length > 0 ? this.currentEntities.kits.find(kit => kit.id == sample.smpl_kit_fk) || !sample.smpl_kit_fk : true);

      samples.forEach(sample => {
        //remaining volume calculation
        let sampleVolumeUnit;
        let smpl_content_volume = 0;
        let contentVolumeInMl = 0;
        
        if (sample.smpl_content_volume && sample.smpl_volume_unit) {
          sampleVolumeUnit = this.getChoiceDescription(sample.smpl_volume_unit);
          contentVolumeInMl = Math.max(sample.smpl_content_volume * sampleVolumeUnit - consumedVolumeInMl, 0);
          smpl_content_volume = contentVolumeInMl / sampleVolumeUnit;
        }

        for (const [key, value] of Object.entries(data)) {
          const stopFields = ["smpl_volume_unit", "id", "entitytype_id"];
          const sampleFields = this.getEntityType("SMPL_SAMPLE")._fields;
          const sampleField = sampleFields.find(sampleField => sampleField.name == key);

          if (sampleField && !stopFields.includes(key)) {
            sample[key] = value;
          }
        }

        sample.smpl_workflow_step_fk = step.id;
        sample.smpl_events_fk = sample.smpl_events_fk.concat(data.id);
        sample.smpl_sample_status_fk = step.smpl_sample_status_fk;
        sample.smpl_content_volume = smpl_content_volume;
      });

      if (samples.length > 0) await this.batchUpdate(samples);
    },
    
    async propagate(e, samples = []) {
      let step = this.getStep(e.smpl_workflow_step_fk);
      let batch = step?.smpl_workflow_step_is_batch ? this.getStep(e.smpl_workflow_step_fk) : null;

      let unit, consumedVolumeInMl = 0;
      if (e.smpl_consumed_volume && e.smpl_volume_unit) {
        unit = this.getChoiceDescription(e.smpl_volume_unit);
        consumedVolumeInMl = e.smpl_consumed_volume * unit;
      }

      let typeEvent = "Collection";
      if (step) typeEvent = batch ? this.getEventTypeByName(step.steps[0].type) : this.getEventTypeByName(step.type);

      for (let i = 0; i < samples.length; i++) {
        const sampleID = samples[i];
        const parentSample = await this.getEntity(sampleID);
        
        let parentSampleVolumeUnit;
        let smpl_content_volume = 0;
        let contentVolumeInMl = 0;
        
        if (parentSample.smpl_content_volume && parentSample.smpl_volume_unit) {
          parentSampleVolumeUnit = this.getChoiceDescription(parentSample.smpl_volume_unit);
          contentVolumeInMl = Math.max(parentSample.smpl_content_volume * parentSampleVolumeUnit - consumedVolumeInMl, 0);
          smpl_content_volume = contentVolumeInMl / parentSampleVolumeUnit;
        }

        const sampleForEvents = await this.getEntity(sampleID);
        let sampleEvents = [];
        
        if (sampleForEvents?.smpl_events_fk) {
          sampleEvents = sampleForEvents.smpl_events_fk;
        }
        
        sampleEvents.push(e.id);

        if (batch) {
          const lineID = this.currentEntities.samples.find(sample => sample.id == sampleID).smpl_workflow_line_fk;
          step = batch.steps.find(step => step.smpl_workflow_line_fk == lineID);
        }

        let sampleUpdate = {
          "smpl_content_volume": smpl_content_volume,
          "smpl_workflow_step_fk": e.smpl_workflow_step_fk,
          "smpl_events_fk": sampleEvents
        };
        
        if (batch) sampleUpdate.smpl_workflow_step_fk = step.id;
        
        if (step.smpl_sample_status_fk) {
          sampleUpdate.smpl_sample_status_fk = (step.smpl_workflow_step_status_change_fk ? step.smpl_workflow_step_status_change_fk : step.smpl_sample_status_fk);
        }

        if (this.getEventTypeById(e.smpl_event_type_fk) != "Storage") {
          if (!sampleUpdate.STORAGE) sampleUpdate.STORAGE = null;
          if (!sampleUpdate.POSITION_COLUMN) sampleUpdate.POSITION_COLUMN = null;
          if (!sampleUpdate.POSITION_ROW) sampleUpdate.POSITION_ROW = null;
        }

        const sample = await this.dapp.$axios.$put(`/entities/${sampleID}`, sampleUpdate);
        this.selectSamples([sample]);
      }
    },
    
    async batchCreate(createData) {
      const params = {
        data: createData,
        options: {},
        async: false,
        save_changes: true,
      };
      
      try {
        await this.$axios.$post('entities/batch', params);
      } catch (error) {
      }
    },
    
    async batchUpdate(updateData) {
      const params = {
        data: updateData,
        options: {
          identify_entities_by: ['id'],
          upsert: false
        },
        async: false,
        save_changes: true,
      };
      
      try {
        await this.$axios.$put('entities/batch', params);
      } catch (error) {
      }
    },
    
    async batchDelete(deleteData) {
      for (let i = 0; i < deleteData.length; i++) {
        await this.dapp.$axios.$delete(`/entities/${deleteData[i].id}`);
      }
    },
    
    exceptionHandler(error) {
      this.dapp.$store.dispatch('exceptionHandler', error);
    },

    //==========================
    // INITIALISATION
    //==========================
    
    async init() {
      const bonhomme = d3.select("#nodeLayer").append("g").attr("id", "bonhomme");
      
      bonhomme.append("line")
        .attr("x1", 0)
        .attr("y1", 0)
        .attr("x2", 0.78 * this.grid.step[1])
        .attr("y2", 0.78 * this.grid.step[1])
        .attr('stroke', "#000")
        .attr('stroke-width', 3)
        .style("stroke-dasharray", "0, 6")
        .style('stroke-linecap', 'round');

      bonhomme.append("text")
        .attr("id", "bonhommeSubject")
        .attr("fill", "#299D8F")
        .style("text-anchor", "start")
        .style("font-size", "1.4em")
        .style("font-weight", 700)
        .style("cursor", "default")
        .attr('x', -10)
        .attr('y', - 0.55 * this.grid.step[1])
        .html("No subject selected")
        .on("click", this.showCustomViewForSubjects);

      bonhomme.append("text")
        .attr("id", "bonhommeCase")
        .style("font-size", "1.2em")
        .style("cursor", "default")
        .attr("fill", "#299D8F")
        .style("text-anchor", "start")
        .style("font-weight", "500")
        .attr("x", -10)
        .attr("y", - 0.55 * this.grid.step[1])
        .attr("dy", "1.4em")
        .html("No case selected")
        .on("click", this.showCustomViewForCases);

      bonhomme.append("circle")
        .attr("cx", 0).attr("cy", 0).attr("r", 22)
        .style("fill", "white")
        .style("stroke", "black")
        .style("stroke-width", 3);

      bonhomme.append("image").attr("xlink:href", this.resources["person"])
        .attr("fill", "black")
        .attr("x", -15).attr("y", -15)
        .attr("width", 30)
        .attr("height", 30);

      bonhomme.append("text").attr("id", "bonhommeContainers")
        .attr("fill", "black")
        .style("text-anchor", "start")
        .style("font-size", "0.7em")
        .style("cursor", "default")
        .attr('x', 30)
        .attr('y', -13)
        .text("Use new containers");

      bonhomme.append("text").attr("id", "bonhommeCollect")
        .attr("fill", "dimGrey")
        .style("text-anchor", "start")
        .style("font-weight", "700")
        .style("cursor", "pointer")
        .attr('x', 30)
        .attr('y', 6)
        .text("Collection")
        .on("click", async (e, d) => {
          if (d3.select("#bonhommeCollect").classed("active")) {
            if (this.currentEntities.subjects.length == 0)
              this.promptNewSubject();
            else if (this.currentEntities.subjects.length == 1 && this.currentEntities.cases.length == 0)
              this.promptNewCase();
            else if (this.currentEntities.subjects.length == 1 && this.currentEntities.cases.length == 1)
              this.standardBatchCollection();
          }
        });
    },
    
async loadWorkflow(wfid) {
  
  this.workflow = await this.dapp.$axios.$get(await this.getRouteURLByName('smpl_load_workflow') + '&workflowId=' + wfid);
  
  // ✅ DIAGNOSTIC COMPLET DU WORKFLOW
  
  // ✅ DIAGNOSTIC DES LIGNES
  let problemLines = [];
  
  this.workflow.lines.forEach((line, index) => {
    const stepsCount = line.steps?.length || 0;
    const hasParent = !!line.smpl_workflow_line_fk;
    const isKit = line.smpl_workflow_line_is_kit;
    
    const lineInfo = {
      index: index,
      id: line.id,
      label: line.smpl_label,
      stepsCount: stepsCount,
      steps: line.steps?.map(s => ({
        id: s.id,
        order: s.smpl_order,
        type: s.type,
        label: s.smpl_label
      })),
      isKit: isKit,
      hasParent: hasParent,
      parentStepId: line.smpl_workflow_step_fk,
      visible: line.visible
    };
    
    
    // Identifier les problèmes
    if (stepsCount < 2) {
      problemLines.push({
        ...lineInfo,
        problem: `Only ${stepsCount} step(s), needs at least 2`
      });
    }
    
    if (stepsCount === 0) {
    }
    
    if (hasParent && !this.getStep(line.smpl_workflow_step_fk)) {
      problemLines.push({
        ...lineInfo,
        problem: `Parent step ${line.smpl_workflow_step_fk} not found`
      });
    }
  });
  
  // ✅ RÉSUMÉ DES PROBLÈMES
  if (problemLines.length > 0) {
  } else {
  }
  
  // Traitement des lignes et étapes
  this.workflow.lines.forEach(async line => {
    line.visible = line.smpl_workflow_line_fk ? false : true;
    if (!this.workflow.smpl_workflow_show_hierarchy) line.visible = true;
    
    line.steps.forEach(async (step, i) => {
      const old_order = step?.smpl_order;
      step.smpl_order = i;
      if (old_order != i) {
        await this.$axios.$put(`entities/${step.id}`, step);
      }
      step.prevSteps = this.getPrevSteps(step);
    });
  });

  this.updateCounts();
  
  // Gérer l'affichage du bonhomme selon le type de workflow
  if (this.workflow.smpl_workflow_is_collection) {
    d3.select("#nodeLayer").select("#bonhomme").style("visibility", "visible");
  } else {
    d3.select("#nodeLayer").select("#bonhomme").style("visibility", "hidden");
  }
  
  // Réinitialiser le zoom
  d3.select('svg').transition().call(this.zoom.transform, d3.zoomIdentity.translate(this.grid.origin[0], this.grid.origin[1]).scale(this.zoomScale));
  
},
    
    async reloadWorkflow() {
      // Sauvegarde l'état de visibilité des lignes
      let lineVisibility = {};
      this.workflow.lines.forEach(line => {
        lineVisibility[line.id] = line?.visible;
      });
      
      // Recharge le workflow
      this.workflow = await this.dapp.$axios.$get(await this.getRouteURLByName('smpl_load_workflow') + '&workflowId=' + this.workflow.id);
      
      // Restaure l'état de visibilité et calcule les étapes précédentes
      this.workflow.lines.forEach(line => {
        line.visible = lineVisibility[line.id];
        line.steps.forEach(step => {
          step.prevSteps = this.getPrevSteps(step);
        });
      });
      
      // Mise à jour des compteurs
      this.updateCounts();
    },
  },

  // CYCLE DE VIE DU COMPOSANT
  
async mounted() {
  try {
    this.loading = true;
    this.loadingProgress = 0;

    // Step 1: Resources (20%)
    this.loadingMessage = this.loadingSteps[0];
    const resources = [
      { key: "batch", name: "batch.svg" },
      { key: "person", name: "person.svg" },
      { key: "branches_hide", name: "branches_hide.png" },
      { key: "branches_show", name: "branches_show.png" },
      { key: "SMPL_logo_2", name: "SMPL_logo_2.png" },
      { key: "LOGO_SBP", name: "LOGO_SBP.svg" }
    ];
    await this.getResources(resources);
    this.loadingProgress = 20;
    this.updateLoadingProgress(1);

    // Step 2: Environment (40%)
    this.loadingMessage = this.loadingSteps[1];
    await this.registerLib({ url: 'https://cdnjs.cloudflare.com/ajax/libs/d3/7.8.5/d3.min.js' }); // ← CDN direct
    this.$nextTick(() => {
      window.addEventListener('resize', this.onResize);
    });
    this.zoom = d3.zoom().scaleExtent([0.1, 2]).on('zoom', this.handleZoom);
    this.initZoom();
    await this.setFormIds();
    this.loadingProgress = 40;
    this.updateLoadingProgress(2);

    // Process URL parameters
    let uri = window.location.href.split('?');
    this.currentProject = uri[0].match(/workspaces\/(\d+)/);
    if (!this.currentProject) {
      this.currentProject = "global";
    } else {
      this.currentProject = this.currentProject[1];
    }

    let desiredWorkflow;
    if (uri.length == 2) {
      let vars = uri[1].split('&');
      let getVars = {};
      let tmp = '';
      vars.forEach(function(v) {
        tmp = v.split('=');
        if (tmp.length == 2)
          getVars[tmp[0]] = tmp[1];
      });
      desiredWorkflow = getVars?.workflow;
    }

    // Step 3: Workflows (60%)
    this.loadingMessage = this.loadingSteps[2];
    await this.getAllWorkflows(desiredWorkflow);
    this.getAllEntityTypes();
    this.loadingProgress = 60;
    this.updateLoadingProgress(3);

    // Step 4: Interface (80%)
    this.loadingMessage = this.loadingSteps[3];
    this.init();
    await this.loadWorkflow(this.workflows[0].id);
    this.loadingProgress = 80;
    this.updateLoadingProgress(4);

    // Step 5: Final setup (100%)
    this.loadingMessage = this.loadingSteps[4];
    this.applyCssStyle();
    this.onResize();

    const customviews = await this.dapp.$axios.$get('/customviews');
    this.customViewIds.samples = customviews.find(cv => cv.name == "smpl")?.id;
    if (!this.customViewIds.samples) this.customViewIds.samples = customviews.find(cv => cv.name == "smpl_global")?.id;
    this.customViewIds.subjects = customviews.find(cv => cv.name == "smpl_subjects")?.id;
    if (!this.customViewIds.subjects) this.customViewIds.subjects = customviews.find(cv => cv.name == "smpl_subjects_global")?.id;
    this.customViewIds.cases = customviews.find(cv => cv.name == "smpl_cases")?.id;
    if (!this.customViewIds.cases) this.customViewIds.cases = customviews.find(cv => cv.name == "smpl_cases_global")?.id;
    this.customViewIds.kits = customviews.find(cv => cv.name == "smpl_kits")?.id;
    if (!this.customViewIds.kits) this.customViewIds.kits = customviews.find(cv => cv.name == "smpl_kits_global")?.id;

    this.loadingProgress = 100;
    this.loadingMessage = "Complete";

    setTimeout(() => {
      this.loading = false;
    }, 300);

  } catch (error) {
    this.loadingMessage = "Error: " + error.message;
  }
},
  
  beforeDestroy() {
    window.removeEventListener('resize', this.onResize);
  },
}