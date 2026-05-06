{
  data() {
    return {
      links: [],
      workflow: null,
      workflows: [],
      steps: [],
      entityTypes: [],
      eventTypes: [],
      width: 100,
      height: 100,
      grid: {
        "origin": [100, 150],
        "step": [320, 160]
      },
      zoom: null,
      zoomScale: 1,
      loading: false,
      formIdToEdit: null,
      creatingForm: false,
      forms: [],
      stepFormId: null,
      lineFormId: null,
      workflowFormId: null,
      subjectFormId: null,
      caseFormId: null,
      kitFormId: null,
      sampleLineFormId: null,
      collectionFormId: null,
      eventFormTemplates: {},
      selectedStep: null,
      resources: []
    }
  },
  computed: {
    loadedForms() {
      if (this.forms.length > 0) return this.forms.every(form => form.form)
    },
    isValidforms() {
      return this.forms.every(form => form.isValidForm)
    }
  },
  methods: {
    //DISPLAY
    onResize() {
      this.height = window.innerHeight - 71
      this.width = window.innerWidth - 8
    },
    handleZoom(e) {
      this.zoomScale = e.transform.k
      d3.selectAll(".layer").attr('transform', e.transform)
    },
    initZoom() {
      d3.select('svg').call(this.zoom)
    },

    //API ROUTES
    async getResources(resources) {
      const folders = await this.dapp.$axios.$get(`/folders`)
      const folderId = folders.find(folder => folder.name == "smpl_resources")?.id
      if (folderId) {
        const files = await this.dapp.$axios.$get(`/folders/${folderId}/files`)
        for (let i = 0; i < resources.length; i++) {
          const resource = resources[i]
          const fileId = files.find(file => file.name == resource.name).id
          if (fileId) {
            const link = await this.dapp.$axios.$get(`/files/download-link/${fileId}`)
            this.resources[resource.key] = link.link.replace("smia_chuv", "chuv").replace("http://", "https://")
          } else {
            await this.$toastNotifier.notifyError('Missing file: ' + name)
          }
        }
      } else {
        await this.$toastNotifier.notifyError('Missing folder: smpl_resources')
      }
    },
    async getRouteURLByName(name) {
      const routes = await this.dapp.$axios.$get(`/user-routes`)
      const route = routes.find(route => route.name == name).url.replace("smia_chuv", "chuv").replace("http://", "https://")
      const currentProject = $nuxt.$store.getters['currentUser/getCurrentProject']
      return `${route}?projectId${currentProject.id ? "=" + currentProject.id : ""}`
    },
    async getAllWorkflows(id = null) {
      this.workflows = []
      let is_wf = false
      if (id) {
        const entity = await this.dapp.$axios.$get(`/entities/${id}`)
        if (entity?.smpl_study_fk) is_wf = true
      }
      const response = await this.dapp.$axios.$get(await this.getRouteURLByName('smpl_get_all_workflows'))
      response.filter(workflow => id ? (is_wf ? workflow.id == id : workflow.smpl_study_fk == id) : true).forEach(workflow => {
        this.workflows.push(workflow)
      })
    },

    //HELPER FUNCTIONS
    async getEntitiesByValue(fieldname, value) {
      const etids = [
        this.$store.getters['entityTypes/getEntityTypeByName']("SMPL_EVENT")?.id,
      ]
      const payload = {
        "entitytype_ids": etids,
        "filter": {
          "type": "bracket",
          "operationCode": "&&",
          "conditions": [
            {
              "type": "condition",
              "operationCode": "=",
              "operand": fieldname,
              "value": value
            }
          ]
        }
      }
      const data = await this.$axios.$post('entities/query', payload)
      return data.entities
    },
    async setFormIds() {
      const forms = await this.dapp.$axios.$get('/forms')
      this.stepFormId = forms.find(form => form.name == "smpl_workflow_step_edit")?.id
      this.lineFormId = forms.find(form => form.name == "smpl_workflow_line_edit")?.id
      this.workflowFormId = forms.find(form => form.name == "smpl_workflow_edit")?.id
      this.subjectFormId = forms.find(form => form.name == "smpl_subject_template")?.id
      this.caseFormId = forms.find(form => form.name == "smpl_case_template")?.id
      this.kitFormId = forms.find(form => form.name == "smpl_kit_template")?.id
      this.sampleLineFormId = forms.find(form => form.name == "smpl_sample_creation_prompt")?.id
      this.collectionFormId = forms.find(form => form.name == "smpl_collection_template")?.id
    },
    getEntityType(name) {
      return this.entityTypes.find(et => et.name == name)
    },
    getAllEntityTypes() {
      this.entityTypes = this.$store.state.entityTypes.entityTypes
      this.entityTypes.forEach(et => {
        et["_fields"] = []
      })
      const fields = this.$store.state.fields.fields
      fields.forEach(field => {
        field._entitytypes.forEach(fet => {
          let et = this.entityTypes.find(et => et.id == fet.id)
          if (et) et["_fields"].push(field)
        })
      })
    },
    getEventTypeByName(name) {
      return this.workflow.eventTypes.find(et => et.smpl_label == name)
    },
    getEventTypeById(id) {
      return this.workflow.eventTypes.find(et => et.id == id)
    },
    getStep(stepId) {
      return this.steps.find(step => step.id == stepId)
    },
    getLine(lineId) {
      const line = this.workflow.lines.find(line => line.id == lineId)
      return line
    },
    getBatch(id) {
      return this.workflow.batches.find(batch => batch.id == id)
    },
    getChoiceId(categoryName, choiceValue) {
      const category = this.$store.state.fields.choiceCategories.find(category => category.name == categoryName)
      return category._choices.find(choice => choice.value == choiceValue).id
    },
    getChoiceValue(choiceId) {
      let value
      this.$store.state.fields.choiceCategories.forEach(category => {
        const choice = category._choices.find(choice => choice.id == choiceId)
        if (choice) value = choice.value
      })
      return value
    },
    getChoiceDescription(choiceId) {
      let description
      this.$store.state.fields.choiceCategories.forEach(category => {
        const choice = category._choices.find(choice => choice.id == choiceId)
        if (choice) description = choice.description
      })
      return description
    },
    async getSamples(ids) {
      let uri = await this.getRouteURLByName('smpl_get_samples_by_steps')
      for (let i = 0; i < ids.length; i++) {
        uri += (i == 0 ? '&steps[]=' : '&steps[]=') + ids[i]
      }
      const response = await this.dapp.$axios.$get(uri)
      return response
    },
    getFieldId(name) {
      const field = this.$store.state.fields.fields.find(field => field.name == name)
      const fieldId = field.id
      return fieldId
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
      }
      try {
        const updatedEntities = await this.$axios.$put('entities/batch', params)
      } catch (error) {
        console.log(error)
      }
    },
    async batchDelete(deleteData) {
      for (let i = 0; i < deleteData.length; i++) {
        await this.dapp.$axios.$delete(`/entities/${deleteData[i].id}`)
      }
    },

    // WORKFLOW DATA SETUP
    // WORKFLOW VISUALIZATION SETUP
    setHorizontalPositions() {
      const workflowLines = this.workflow.lines.filter(line => line?.steps.length > 0)
      let positions = []
      for (let i = 0; i < workflowLines.length; i++) {
        var line = workflowLines[i]
        if (line?.smpl_workflow_line_fk) {
          let index = positions.findLastIndex(position => position.smpl_workflow_line_fk == line.smpl_workflow_line_fk)
          if (index < 0) index = positions.findIndex(position => position.id == line.smpl_workflow_line_fk)
          positions.splice(index + 1, 0, line)
        } else {
          positions.push(line)
        }
      }
      for (let i = 0; i < positions.length; i++) {
        var line = positions[i]
        line.x = i + 1
      }
    },
    async setVerticalPositions() {
      this.workflow.batches.forEach(batch => {
        batch.xMax = 0
        batch.xMin = null
      })
      const lines = this.workflow.lines.filter(line => line?.steps.length > 0)
      lines.forEach(line => {
        var position = 0
        if (this.workflow.smpl_workflow_show_hierarchy) {
          if (line?.smpl_workflow_line_fk) {
            position = this.getStep(line?.smpl_workflow_step_fk).y
          }
        } else {
          if (line?.smpl_workflow_line_fk) {
            const parentLine = this.getLine(line.smpl_workflow_line_fk)
            position = parentLine.steps[0].y + 1
          }
        }
        line.steps.sort((a, b) => (a.fy ? a.fy : a.y) - (b.fy ? b.fy : b.y))
        line.steps.forEach((step, index) => {
          if (step?.smpl_workflow_step_batch_fk) {
            const batch = this.workflow.batches.find(batch => batch.id == step.smpl_workflow_step_batch_fk)
            if (!batch?.y) batch.y = 0
            batch.y = Math.max(position, batch.y)
            position = batch.y
            batch.xMin = batch.xMin ? Math.min(batch.xMin, step.x) : step.x
            batch.xMax = batch.xMax ? Math.max(batch.xMax, step.x) : step.x
          }
          if (!step.smpl_workflow_step_batch_fk) {
            while (this.workflow.batches.find(batch => batch.y == position && batch.id != step?.smpl_workflow_step_batch_fk)) {
              position++
            }
          }
          step.y = line.smpl_workflow_line_is_kit && step.smpl_order == 0 ? 0 : position
          step.x = line.x
          position++
        })
      })
    },
    aggregateSteps() {
      this.steps = []
      this.workflow.batches.forEach(batch => {
        batch.active = false
      })
      this.workflow.lines.forEach(line => {
        line.steps.forEach(step => {
          step.active = false
          this.steps.push(step)
        })
      })
    },
    batchAlignment() {
      this.workflow.batches.forEach(batch => {
        const steps = this.steps.filter(step => step?.smpl_workflow_step_batch_fk == batch.id)
        var lowest = 0
        steps.forEach(step => {
          lowest = Math.max(lowest, step.y)
        })
        batch.y = lowest
        steps.forEach(step => {
          step.yBatch = batch.y
        })
      })
      this.setVerticalPositions()
    },
    removeEmptyRows() {
      var rowCount = 0
      this.steps.forEach(step => {
        rowCount = Math.max(rowCount, step.y)
      })
      var positions = Array(rowCount + 1).fill();
      this.steps.forEach(step => {
        if (!positions[step.y]) positions[step.y] = [step.id]
        else positions[step.y].push(step.id)
      })
      positions = positions.filter(d => d)
      positions.forEach((elements, index) => {
        if (elements) {
          elements.forEach(id => {
            var step = this.getStep(id)
            step.y = index
            if (step?.smpl_workflow_step_batch_fk) {
              const batch = this.workflow.batches.find(batch => batch.id == step.smpl_workflow_step_batch_fk)
              batch.y = index
            }
          })
        }
      })
    },
    setLinks() {
      const lines = this.workflow.lines.filter(line => line?.steps.length > 0)
      this.links = []
      for (let i = 0; i < lines.length; i++) {
        const line = lines[i]
        var parentStep = line.smpl_workflow_line_fk ? this.getStep(line.smpl_workflow_step_fk) : {
          id: (this.workflow?.smpl_workflow_is_collection ? -1 : null),
          x: 0,
          y: 0
        }
        if (parentStep.id) {
          const optionalOffset = 0.12
          const sampleStep = line.steps[1]
          this.links.push({
            id: (parentStep.id * 10000 + sampleStep.id) * 10,
            source: [((parentStep.x + (parentStep.smpl_workflow_step_is_optional ? optionalOffset : 0)) + 0.78 * (this.grid.step[1] / this.grid.step[0])) * this.grid.step[0], (parentStep.y + 0.78) * this.grid.step[1]],
            target: [(sampleStep.x - 0.22 * (this.grid.step[1] / this.grid.step[0])) * this.grid.step[0], (parentStep.y + 0.78) * this.grid.step[1]],
            origin: [parentStep.x * this.grid.step[0], parentStep.y * this.grid.step[1]],
            dashed: true
          })
          if (this.workflow.smpl_workflow_show_hierarchy) {
            this.links.push({
              id: parentStep.id * 1000 + sampleStep.id,
              source: [(sampleStep.x - 0.22 * (this.grid.step[1] / this.grid.step[0])) * this.grid.step[0], (parentStep.y + 0.78) * this.grid.step[1]],
              target: [sampleStep.x * this.grid.step[0], sampleStep.y * this.grid.step[1]],
              origin: [parentStep.x * this.grid.step[0], parentStep.y * this.grid.step[1]],
              dashed: true
            })
          } else {
            this.links.push({
              id: parentStep.id * 1000 + sampleStep.id,
              source: [(sampleStep.x - 0.22 * (this.grid.step[1] / this.grid.step[0])) * this.grid.step[0], (parentStep.y + 0.78) * this.grid.step[1]],
              target: [(sampleStep.x - 0.22 * (this.grid.step[1] / this.grid.step[0])) * this.grid.step[0], sampleStep.y * this.grid.step[1]],
              origin: [parentStep.x * this.grid.step[0], parentStep.y * this.grid.step[1]],
              dashed: true
            })
            this.links.push({
              id: parentStep.id * 100000 + sampleStep.id * 100,
              source: [(sampleStep.x - 0.22 * (this.grid.step[1] / this.grid.step[0])) * this.grid.step[0], sampleStep.y * this.grid.step[1]],
              target: [sampleStep.x * this.grid.step[0], sampleStep.y * this.grid.step[1]],
              origin: [parentStep.x * this.grid.step[0], parentStep.y * this.grid.step[1]],
              dashed: true
            })
          }
        }
      }
    },
    async deleteStep(step) {
      await this.$axios.$delete('entities/' + step.id)
    },
    async deleteLine(line) {
      line = this.getLine(line.id)
      if (line.steps.length <= 2) {
        let entitiesToUpdate = await this.getEntitiesByValue("smpl_workflow_step_fk", line.steps[0].id)
        await entitiesToUpdate.forEach(async entity => {
          await this.$axios.$put(`entities/${entity.id}`, {
            "smpl_workflow_step_fk": null
          })
        })
        entitiesToUpdate = await this.getEntitiesByValue("smpl_workflow_step_fk", line.steps[1].id)
        await entitiesToUpdate.forEach(async entity => {
          await this.$axios.$put(`entities/${entity.id}`, {
            "smpl_workflow_step_fk": null
          })
        })
        entitiesToUpdate = await this.getEntitiesByValue("smpl_workflow_line_fk", line.id)
        await entitiesToUpdate.forEach(async entity => {
          await this.$axios.$put(`entities/${entity.id}`, {
            "smpl_workflow_line_fk": null
          })
        })
        await this.$axios.$delete('entities/' + line.steps[0].id)
        await this.$axios.$delete('entities/' + line.steps[1].id)
        await this.$axios.$delete('entities/' + line.id)
      } else {
        await this.$toastNotifier.notifyError('Remove steps before removing line')
      }
    },
    selectStep(d) {
      if (this.selectedStep?.id == d.id) {
        this.selectedStep = null
      } else {
        this.selectedStep = d
      }
      this.update()
    },
    async createBatch(step) {
      this.selectStep(step)
      const batch = await this.$axios.$post('entities', {
        "entitytype_id": step.entitytype_id,
        "smpl_workflow_fk": step.smpl_workflow_fk,
        "smpl_workflow_step_is_batch": true,
        "smpl_event_type_fk": step.smpl_event_type_fk,
        "smpl_step_form_id": step?.smpl_step_form_id
      })
      await this.$axios.$put(`entities/${step.id}`, {
        "smpl_workflow_step_batch_fk": batch.id
      })
      await this.reloadWorkflow()
    },
    async joinBatch(step, batch) {
      this.selectStep(step)
      const updateData = [{
        "id": step.id,
        "smpl_workflow_step_batch_fk": batch.id,
        "smpl_step_form_id": batch?.smpl_step_form_id
      }, {
        "id": batch.id,
      }]
      await this.batchUpdate(updateData)
      await this.reloadWorkflow()
    },
    async leaveBatch(step) {
      this.selectStep(step)
      const batch = this.getBatch(step.smpl_workflow_step_batch_fk)
      const updateData = [{
        "id": step.id,
        "smpl_workflow_step_batch_fk": null
      }]
      await this.batchUpdate(updateData)
      if (batch.steps.length < 2) {
        await this.deleteStep(batch)
      }
      await this.reloadWorkflow()
    },

    //WORKFLOW VISUALIZATION AND DATA BINDING
    enterSteps() {
      const steps = this.steps

      function wrap(text, width) {
        text.each(function() {
          var text = d3.select(this),
            words = text.text().split(/\s+/).reverse(),
            word,
            line = [],
            lineNumber = 0,
            lineHeight = 1.1,
            x = text.attr("x"),
            y = text.attr("y"),
            dy = 0,
            tspan = text.text(null)
            .append("tspan")
            .attr("x", x)
            .attr("y", y)
            .attr("dy", dy + "em");
          while (word = words.pop()) {
            line.push(word);
            tspan.text(line.join(" "));
            if (tspan.node().getComputedTextLength() > width) {
              line.pop();
              tspan.text(line.join(" "));
              line = [word];
              if (lineNumber < 1) {
                tspan = text.append("tspan")
                  .attr("x", x)
                  .attr("y", y)
                  .attr("dy", ++lineNumber * lineHeight + dy + "em")
                  .text(word);
              } else {
                tspan.text(tspan.text() + "…")
                return
              }
            }
          }
        });
      }

      /// SAMPLE LINES
      d3.select("#lineLayer").selectAll(".sampleLine").data(this.workflow.lines, d => d.id).exit().remove()
      const sampleLineEnter = d3.select("#lineLayer")
        .selectAll(".sampleLine").data(this.workflow.lines, d => d.id).enter()
        .append("g").classed("sampleLine", true)

      const removeBranchEnter = sampleLineEnter.filter(d => (this.workflow.smpl_workflow_is_collection || d.smpl_workflow_line_fk)).append("g").attr("class", "removeStep").style("cursor", "pointer")
        .style("visibility", "hidden")
        .attr("transform", (d) => "translate(-25," + (d.steps[0].y - 0.5) * this.grid.step[1] + ")scale(1)")
      removeBranchEnter.append("circle").attr("r", 8).attr("fill", "white").attr("stroke", "#333333").attr("stroke-width", 1.5)
      removeBranchEnter.append("line").attr("x1", -4).attr("x2", 4).attr("stroke", "#333333").attr("stroke-width", 2)
      removeBranchEnter.on("click", async (e, line) => {
        await this.deleteLine(line)
        await this.reloadWorkflow()
      })

      sampleLineEnter.append("text")
        .attr("fill", "#299D8F")
        .style("text-anchor", "start")
        .style("font-size", "1.4em")
        .style("font-weight", 700)
        .style("cursor", "default")
        .attr('x', -10)
        .attr('y', d => (this.getLine(d.id).steps[0].y - 0.5) * this.grid.step[1])
        .classed("clickable", true)
        .text(d => (d.smpl_workflow_line_quantity ? d.smpl_workflow_line_quantity + "× " : "") + (d.smpl_label ? d.smpl_label : ""))
        .call(wrap, this.grid.step[0] - 40)
        .on("click", async (e, d) => {
          this.forms = [{
            id: this.lineFormId,
            entityToUpdate: d,
            form: null,
            isValidForm: true
          }]
          await this.loadForms()
        })

      sampleLineEnter.append("line").attr("class", "bigLine")
        .attr("stroke", d => {
          const color = this.getChoiceDescription(d.smpl_workflow_line_color)
          return color ? color : "darkGray"
        })
        .attr("stroke-width", 8)

      sampleLineEnter.append("line").attr("class", "endLine")
        .attr("stroke", d => {
          const color = this.getChoiceDescription(d.smpl_workflow_line_color)
          return color ? color : "darkGray"
        })
        .attr("stroke-width", 4)

      const sampleLineUpdate = d3.select("#lineLayer").selectAll(".sampleLine").data(this.workflow.lines, d => d.id)

      sampleLineUpdate.transition().attr("transform", d => {
        return "translate(" + (d.steps[0].x * this.grid.step[0]) + ",0)scale(1)"
      })

      sampleLineUpdate.select("text")
        .transition()
        .attr('x', -10)
        .attr('y', d => (this.getLine(d.id).steps[0].y - 0.5) * this.grid.step[1])

      sampleLineUpdate.select(".bigLine")
        .attr("x1", 0)
        .attr("y1", d => this.getLine(d.id).steps[0].y * this.grid.step[1])
        .attr("x2", 0)
        .attr("y2", d => {
          const steps = this.getLine(d.id).steps
          const endStep = steps[steps.length - 1]
          return (endStep.y + 0.4) * this.grid.step[1]
        })

      sampleLineUpdate.select(".endLine")
        .attr("x1", -12)
        .attr("y1", d => {
          const steps = this.getLine(d.id).steps
          const endStep = steps[steps.length - 1]
          return (endStep.y + 0.4) * this.grid.step[1]
        })
        .attr("x2", 12)
        .attr("y2", d => {
          const steps = this.getLine(d.id).steps
          const endStep = steps[steps.length - 1]
          return (endStep.y + 0.4) * this.grid.step[1]
        })

      /// STEPS
      d3.select("#nodeLayer").selectAll(".node").data(steps, d => d.id).exit().remove()
      const nodesEnter = d3.select("#nodeLayer").selectAll(".node").data(steps, d => d.id).enter()
        .append("g").attr("class", d => "n" + d.id).classed("node", true).style("opacity", 0)

      const optionalOffset = 0.12
      nodesEnter.append("path").classed("optionalPath", true)
        .attr("d", d => "M " + (-optionalOffset * this.grid.step[0]) + ", -35 L 0, -35 L 0, 75, L " + (-optionalOffset * this.grid.step[0]) + ", 75")
        .style("fill", "none")
        .attr('stroke', d => {
          const color = this.getChoiceDescription(this.getLine(d.smpl_workflow_line_fk).smpl_workflow_line_color)
          return color ? color : "darkGray"
        })
        .attr('stroke-width', 5)
        .style("stroke-dasharray", "5, 5")
        .style('opacity', 0)

      const derivation = nodesEnter.append("g").classed("derivation", true)
        .style("visibility", d => {
          const et = this.getEventTypeByName(d.type)
          return et.smpl_yields_derivative ? "visible" : "hidden"
        })

      derivation.append("line")
        .attr("x1", 0)
        .attr("y1", 0)
        .attr("x2", 0.78 * this.grid.step[1])
        .attr("y2", 0.78 * this.grid.step[1])
        .attr('stroke', "#000")
        .attr('stroke-width', 3)
        .style("stroke-dasharray", "0, 6")
        .style('stroke-linecap', 'round')

      const addBranchEnter = derivation.append("g").attr("class", "addBranch").style("cursor", "pointer")
        .attr("transform", d => "translate(" + (0.78 * this.grid.step[1]) + "," + (0.78 * this.grid.step[1]) + ")scale(1)")
      addBranchEnter.append("circle").attr("r", 10).attr("fill", "white").attr("stroke", "#333333").attr("stroke-width", 1.5)
      addBranchEnter.append("line").attr("x1", -5).attr("x2", 5).attr("stroke", "#333333").attr("stroke-width", 2)
      addBranchEnter.append("line").attr("y1", -5).attr("y2", 5).attr("stroke", "#333333").attr("stroke-width", 2)
      addBranchEnter.on("click", async (e, d) => {
        const parentLine = this.getLine(d.smpl_workflow_line_fk)
        const childrenLines = this.workflow.lines.filter(line => line.smpl_workflow_step_fk == d.id)
        this.forms = [{
          id: this.lineFormId,
          form: null,
          isValidForm: true,
          defaultEntity: {
            "entitytype_id": parentLine.entitytype_id,
            "smpl_workflow_step_fk": d.id ? d.id : null,
            "smpl_workflow_line_fk": parentLine.id,
            "smpl_workflow_fk": this.workflow.id,
            "smpl_order": parentLine.smpl_order + childrenLines.length + 1,
            "smpl_workflow_line_is_kit": false
          }
        }]
        await this.loadForms()
      })

      const addStepEnter = nodesEnter.filter(d => (!["Container creation"].includes(d.type))).append("g").attr("class", "addStep").style("cursor", "pointer")
        .attr("transform", d => "translate(0," + (0.3 * this.grid.step[1] - 5) + ")scale(1)")
        .attr("visibility", d => (d.type == "Container creation") ? "hidden" : "visible")
      addStepEnter.append("circle").attr("r", 10).attr("fill", "white").attr("stroke", "#333333").attr("stroke-width", 1.5)
      addStepEnter.append("line").attr("x1", -5).attr("x2", 5).attr("stroke", "#333333").attr("stroke-width", 2)
      addStepEnter.append("line").attr("y1", -5).attr("y2", 5).attr("stroke", "#333333").attr("stroke-width", 2)
      addStepEnter.on("click", async (e, d) => {
        const line = this.getLine(d.smpl_workflow_line_fk)
        const currentStepOrder = Number(d.smpl_order)
        this.forms = [{
          id: this.stepFormId,
          form: null,
          isValidForm: true,
          defaultEntity: {
            "entitytype_id": d.entitytype_id,
            "smpl_order": currentStepOrder + 1,
            "smpl_workflow_step_is_batch": false,
            "smpl_workflow_fk": this.workflow.id,
            "smpl_workflow_line_fk": d.smpl_workflow_line_fk,
          }
        }]
        await this.loadForms()

        const stepsToUpdate = line.steps.
        filter(step => Number(step.smpl_order) > currentStepOrder).
        map(step => {
          return {
            id: step.id,
            smpl_order: Number(step.smpl_order) + 1
          }
        })
        await Promise.all(stepsToUpdate.map(async entityStep => {
          await this.$axios.$put(`entities/${entityStep.id}`, entityStep)
        }))
        await this.reloadWorkflow()
      })

      const removeStepEnter = nodesEnter.filter(d => d.smpl_order > 1).append("g").attr("class", "removeStep").style("cursor", "pointer").style("visibility", "hidden")
        .attr("transform", d => "translate(-25,0)scale(1)").attr("visibility", "hidden")
      removeStepEnter.append("circle").attr("r", 8).attr("fill", "white").attr("stroke", "#333333").attr("stroke-width", 1.5)
      removeStepEnter.append("line").attr("x1", -4).attr("x2", 4).attr("stroke", "#333333").attr("stroke-width", 2)
      removeStepEnter.on("click", async (e, d) => {
        await this.deleteStep(d)
        await this.reloadWorkflow()
      })

      nodesEnter.filter(d => {
        if (this.getEventTypeById(d.smpl_event_type_fk)?.smpl_is_alias) return false
        if (d.smpl_order > 1) return true
        return false
      }).append("image")
        .attr("xlink:href", d => (d.smpl_workflow_step_form_id ? this.resources["files"] : this.resources["files-empty"]))
        .style("cursor", "pointer")
        .attr("x", d => -50 - (d.smpl_workflow_step_is_optional ? optionalOffset * this.grid.step[0] : 0))
        .attr("y", -12)
        .attr("width", 25)
        .attr("height", 25)
        .on("click", async (e, d) => {
          if (e.altKey) {
            await this.$axios.$put(`/entities/${d.id}`, {
              "smpl_workflow_step_form_id": null
            })
            await this.$axios.$delete('/forms/' + d.smpl_workflow_step_form_id)
            d.smpl_workflow_step_form_id = null
            await this.reloadWorkflow()
          } else {
            if (this.creatingForm) {
              return
            }
            const typeName = d.type
            if (!d.smpl_workflow_step_form_id) {
              this.creatingForm = true
              const eventType = this.getEventTypeByName(typeName)
              const form = await this.duplicateForm(eventType.smpl_template_form_id, d.id + " " + d.type)
              d.smpl_workflow_step_form_id = form.id
              await this.$axios.$put(`/entities/${d.id}`, {
                "smpl_workflow_step_form_id": form.id
              })
              this.creatingForm = false
            }
            await this.reloadWorkflow()
            this.formIdToEdit = d.smpl_workflow_step_form_id
          }
        })

      nodesEnter.append("path").classed("inputIcon", true)
        .style("visibility", d => (d.type != "Input" ? "hidden" : "visible"))
        .attr("d", "M -14,-10 L 14,-10 L 0,10 Z")
        .attr("stroke", d => {
          const color = this.getChoiceDescription(this.getLine(d.smpl_workflow_line_fk).smpl_workflow_line_color)
          return color ? color : "darkGray"
        })
        .attr('stroke-width', 4)
        .attr('fill', 'white')

      nodesEnter.append("circle").classed("icon", true)
        .style("visibility", d => (d.type == "Input" ? "hidden" : "visible"))
        .attr('cx', 0)
        .attr('cy', 0)
        .attr('r', 10)
        .style('stroke', 'no-stroke')
        .on("click", (e, d) => {
          this.selectStep(d)
        })

      nodesEnter.append("text")
        .classed("nodeType", true)
        .attr("fill", "black")
        .style("text-anchor", "start")
        .style("font-size", "0.7em")
        .style("cursor", "default")
        .attr('x', 18)
        .attr('y', -13)

      nodesEnter.append("text")
        .classed("nodeName", true)
        .attr("fill", "black")
        .style("text-anchor", "start")
        .style("font-weight", "700")
        .attr('x', 18)
        .attr('y', 6)
        .filter(d => !["Creation", "Allocation"].includes(d.type))
        .classed("clickable", true)
        .on("click", async (e, d) => {
          this.forms = [{
            id: this.stepFormId,
            entityToUpdate: d,
            form: null,
            isValidForm: true
          }]
          await this.loadForms()
        })

      nodesEnter.append("text")
        .classed("nodeId", true)
        .style("text-anchor", "end")
        .style("font-size", "0.4em")
        .style("cursor", "default")
        .attr("fill", "#A0A0A0")
        .style("opacity", 0.5)
        .attr('x', -15)
        .attr('y', 21)

      const nodesUpdate = d3.select("#nodeLayer").selectAll(".node").data(steps, d => d.id)
        .classed("selected", (d) => this.selectedStep?.id == d.id)

      nodesUpdate.select(".optionalPath").style("opacity", d => d.smpl_workflow_step_is_optional ? 1 : 0)

      nodesUpdate.select("image")
        .attr("xlink:href", d => (d.smpl_step_form_id ? this.resources["files"] : this.resources["files-empty"]))
        .style("visibility", (d) => {
          if (d?.smpl_workflow_step_batch_fk) return "hidden"
          return "visible"
        })

      nodesUpdate.select(".icon")
        .attr('cx', 0)
        .attr('cy', 0)
        .attr('r', 9)
        .attr("stroke", d => {
          const color = this.getChoiceDescription(this.getLine(d.smpl_workflow_line_fk).smpl_workflow_line_color)
          return color ? color : "darkGray"
        })
        .attr('stroke-width', 4)

      nodesUpdate.select(".derivation")
        .select("text")
        .html(d => d.open ? "hide" : "show")

      nodesUpdate.select('.nodeType')
        .text(d => {
          if (d.smpl_label) {
            if (this.getEventTypeByName(d.type).smpl_is_alias) {
              return "alias to " + d.smpl_workflow_step_goto_fk
            } else {
              return d.type + (d.smpl_workflow_step_is_optional ? " (opt.)" : "")
            }
          } else return ""
        })

      nodesUpdate.select('.nodeName')
        .style("cursor", d => d.active ? "pointer" : "default")
        .text(d => d.smpl_label ? d.smpl_label : d.type)
        .call(wrap, this.grid.step[0] - 40)

      nodesUpdate.select('.nodeId').text(d => d.id)

      nodesUpdate
        .transition()
        .attr("transform", d => "translate(" + ((d.x + (d.smpl_workflow_step_is_optional ? optionalOffset : 0)) * this.grid.step[0]) + "," + ((d.dragged ? d.fy : d.y) * this.grid.step[1]) + ")scale(1)")
        .style("opacity", 1)

      //BATCHES
      d3.select("#batchLayer").selectAll(".batch").data(this.workflow.batches, d => d.id).exit().remove()
      const batchEnter = d3.select("#batchLayer").selectAll(".batch").data(this.workflow.batches, d => d.id).enter()
        .append("g").classed("batch", true).style("opacity", 0)
        .attr("x", this.grid.step[0])
        .attr("y", d => d.y * this.grid.step[1])

      batchEnter.append('line').attr('stroke', "#EEE").attr('stroke-width', 72)
        .attr("x1", d => (d.xMin - 0.4) * this.grid.step[0])
        .attr("y1", 0)
        .attr("x2", d => (d.xMax + 1) * this.grid.step[0])
        .attr("y2", 0)

      batchEnter.append("image")
        .attr("xlink:href", d => (d.smpl_step_form_id ? this.resources["files"] : this.resources["files-empty"]))
        .attr("width", 25)
        .attr("height", 25)
        .attr("x", d => (d.xMin - 0.4) * this.grid.step[0] + 18)
        .attr("y", -15)
        .on("click", async (e, d) => {
          if (e.altKey) {
            if (d.smpl_step_form_id) {
              await this.$axios.$put(`/entities/${d.id}`, {
                "smpl_step_form_id": null
              })
              await this.$axios.$delete('/forms/' + d.smpl_step_form_id)
              d.smpl_step_form_id = null
              await this.reloadWorkflow()
            }
          } else {
            if (this.creatingForm) {
              return
            }
            const eventType = this.getEventTypeById(d.smpl_event_type_fk)
            if (!d.smpl_step_form_id) {
              const form = await this.duplicateForm(eventType.smpl_template_form_id, d.id + " " + d.type)
              let updateData = [{
                "id": d.id,
                "smpl_step_form_id": form.id
              }]
              d.steps.forEach(step => {
                updateData.push({
                  "id": step.id,
                  "smpl_step_form_id": form.id
                })
              })
              await this.batchUpdate(updateData)
            }
            await this.reloadWorkflow()
            this.formIdToEdit = d.smpl_step_form_id
          }
        })

      batchEnter.append("text")
        .classed("nodeId", true)
        .style("text-anchor", "end")
        .style("font-size", "0.4em")
        .style("cursor", "default")
        .attr("fill", "#A0A0A0")
        .style("opacity", 0.5)
        .attr("x", 0.6 * this.grid.step[0] + 38)
        .attr("y", d => d.y * this.grid.step[1] + 24)
        .text(d => d.id)

      const batchUpdate = d3.select("#batchLayer").selectAll(".batch").data(this.workflow.batches, d => d.id)
        .transition().style("opacity", 1)

      batchUpdate.select("image").attr("xlink:href", d => (d.smpl_step_form_id ? this.resources["files"] : this.resources["files-empty"]))

      batchUpdate.select("image").transition()
        .attr("x", d => (d.xMin - 0.4) * this.grid.step[0] + 18)
        .attr("y", d => d.y * this.grid.step[1] - 15)
        .style("cursor", d => d.active ? "pointer" : "default")

      batchUpdate.select("line").transition()
        .attr("x1", d => (d.xMin - 0.4) * this.grid.step[0])
        .attr("y1", d => d.y * this.grid.step[1])
        .attr("x2", d => (d.xMax + 1) * this.grid.step[0])
        .attr("y2", d => d.y * this.grid.step[1])
    },

    enterLinks() {
      d3.select("#linkLayer").selectAll(".link").data(this.links, d => d.id).exit().remove()
      d3.select("#linkLayer").selectAll(".link").data(this.links, d => d.id).enter()
        .append("line").classed("link", true)
        .style("stroke-dasharray", "0, 6")
        .style('stroke-linecap', 'round')
        .attr('stroke', "black")
        .attr('stroke-width', 3)
        .attr("x1", d => d.origin[0])
        .attr("y1", d => d.origin[1])
        .attr("x2", d => d.origin[0])
        .attr("y2", d => d.origin[1])

      d3.select("#linkLayer").selectAll(".link").data(this.links, d => d.id)
        .transition()
        .style("opacity", 1)
        .attr("x1", d => d.source[0])
        .attr("y1", d => d.source[1])
        .attr("x2", d => d.target[0])
        .attr("y2", d => d.target[1])
    },

    enterToolbar() {
      const toolbar = d3.select("#toolbar")
      toolbar.html("")
      toolbar.append("img").classed("smpllogo", true)
        .attr("src", this.resources.SMPL_logo_2)

      const workflowGroup = toolbar.append("div").attr("class", "toolbarGroup")
      const workflowSelector = workflowGroup.append("div").attr("class", "toolbarElement")
      workflowSelector.append("label").html("Workflow")
      const select = workflowSelector.append("select").attr("id", "wfSelector")
        .on("change", async (e, d) => {
          const value = select.property("value")
          if (value == "new") {
            this.forms = [{
              id: this.workflowFormId,
              form: null,
              isValidForm: true,
              defaultEntity: {
                "entitytype_id": this.getEntityType("SMPL_WORKFLOW").id
              }
            }]
            await this.loadForms()
          } else this.loadWorkflow(value)
        })

      this.workflows.forEach(wf => {
        select.append("option").property("selected", () => wf.id == this.workflow.id).attr("value", wf.id).html(wf.study + ": " + wf.smpl_label)
      })
      select.append("option").attr("value", "new").html("New workflow")

      workflowGroup.append("a").text("Workflow settings").on("click", async (e, d) => {
        this.forms = [{
          id: this.workflowFormId,
          entityToUpdate: this.workflow,
          form: null,
          isValidForm: true
        }]
        await this.loadForms()
      })

      const group2 = toolbar.append("div").attr("class", "toolbarGroup")
      group2.append("a").text(d => this.workflow.smpl_workflow_subject_form_id ? 'Subject form' : 'Subject form').on("click", async (e, d) => {
        if (this.creatingForm) {
          return
        }
        const formLabel = this.workflow.id + '_new_subject'
        if (!this.workflow.smpl_workflow_subject_form_id) {
          const newForm = await this.duplicateForm(this.subjectFormId, formLabel)
          await this.$axios.$put(`/entities/${this.workflow.id}`, {
            "smpl_workflow_subject_form_id": newForm.id
          })
          this.workflow.smpl_workflow_subject_form_id = newForm.id
        }
        this.formIdToEdit = this.workflow.smpl_workflow_subject_form_id
      })

      group2.append("a").text(d => this.workflow.smpl_workflow_case_form_id ? 'Case form' : 'Case form').on("click", async (e, d) => {
        if (this.creatingForm) {
          return
        }
        const formLabel = this.workflow.id + '_new_case'
        if (!this.workflow.smpl_workflow_case_form_id) {
          const newForm = await this.duplicateForm(this.caseFormId, formLabel)
          await this.$axios.$put(`/entities/${this.workflow.id}`, {
            "smpl_workflow_case_form_id": newForm.id
          })
          this.workflow.smpl_workflow_case_form_id = newForm.id
        }
        this.formIdToEdit = this.workflow.smpl_workflow_case_form_id
      })

      group2.append("a").text('Collection form').on("click", async (e, d) => {
        if (this.creatingForm) {
          return
        }
        if (!this.workflow.smpl_workflow_collection_form_id) {
          const formLabel = this.workflow.id + '_new_collection'
          const et = this.getEventTypeByName("Collection")
          const newForm = await this.duplicateForm(et.smpl_template_form_id, formLabel)
          await this.$axios.$put(`/entities/${this.workflow.id}`, {
            "smpl_workflow_collection_form_id": newForm.id
          })
          this.workflow.smpl_workflow_collection_form_id = newForm.id
        }
        this.formIdToEdit = this.workflow.smpl_workflow_collection_form_id
      })

      group2.append("a").text(d => this.workflow.smpl_workflow_kit_form_id ? 'Kit form' : 'Kit form').on("click", async (e, d) => {
        if (this.creatingForm) {
          return
        }
        const formLabel = this.workflow.id + '_new_kit'
        if (!this.workflow.smpl_workflow_kit_form_id) {
          const newForm = await this.duplicateForm(this.kitFormId, formLabel)
          await this.$axios.$put(`/entities/${this.workflow.id}`, {
            "smpl_workflow_kit_form_id": newForm.id
          })
          this.workflow.smpl_workflow_kit_form_id = newForm.id
        }
        this.formIdToEdit = this.workflow.smpl_workflow_kit_form_id
      })

      const group3 = toolbar.append("div").attr("class", "toolbarGroup")
      if (this.selectedStep) {
        const baSelector = group3.append("div").attr("class", "toolbarElement")
        baSelector.append("label").html("Edit batch")
        const batchActionSelect = baSelector.append("select").attr("id", "baSelector")
          .on("change", async (e, d) => {
            const value = batchActionSelect.property("value")
            if (value == "new") {
              await this.createBatch(this.selectedStep)
            } else if (value == "leave") {
              await this.leaveBatch(this.selectedStep)
            } else {
              await this.joinBatch(this.selectedStep, this.getBatch(value))
            }
            this.reloadWorkflow
          })

        if (this.selectedStep.smpl_workflow_step_batch_fk) {
          batchActionSelect.append("option").property("disabled", true).property("selected", true).attr("value", "").html("Select action")
          batchActionSelect.append("option").attr("value", "leave").html("Leave batch")
        } else {
          batchActionSelect.append("option").property("diasbled", true).property("selected", true).attr("value", "").html("Select action")
          this.workflow.batches.filter(batch => batch.smpl_event_type_fk == this.selectedStep.smpl_event_type_fk).forEach(batch => {
            batchActionSelect.append("option").attr("value", batch.id).html("Join batch " + batch.id)
          })
          batchActionSelect.append("option").attr("value", "new").html("New batch")
        }
      }
    },

    async loadForms() {
      try {
        this.loading = true
        this.forms.forEach(async (form) => {
          form.form = await this.dapp.$store.dispatch('forms/fetchForm', form.id)
        })
      } catch (error) {
        this.exceptionHandler(error)
      } finally {
        this.loading = false
      }
    },

    exceptionHandler(error) {
      this.dapp.$store.dispatch('exceptionHandler', error)
    },

    async formSubmitted(form) {
      form.submitted = true
      if (this.forms.every(form => form.submitted)) {
        await this.$toastNotifier.notifySuccess('Form submited successfully')
        if (form.e) {
          const e = form.e
          if (e.entitytype_id == this.getEntityType("SMPL_WORKFLOW_STEP").id) {
            const eventType = this.getEventTypeById(e.smpl_event_type_fk)
            if (!eventType) {
              await this.$toastNotifier.notifyError(`Type d'événement introuvable (ID: ${e.smpl_event_type_fk})`)
              await this.reloadWorkflow()
              return
            }
            const typeName = eventType.smpl_label
            const stepHasForm = !!e.smpl_workflow_step_form_id
            if (typeName != "GoTo" && !stepHasForm) {
              if (!eventType.smpl_template_form_id) {
                await this.$toastNotifier.notifyError(
                  `Configuration manquante: Aucun formulaire template pour "${typeName}". ` +
                  `Veuillez configurer un template dans la base de données.`
                )
                await this.reloadWorkflow()
                return
              }
              try {
                const newForm = await this.duplicateForm(
                  eventType.smpl_template_form_id,
                  e.id + " " + typeName
                )
                await this.$axios.$put(`/entities/${e.id}`, {
                  "smpl_workflow_step_form_id": newForm.id
                })
              } catch (error) {
                await this.$toastNotifier.notifyError(
                  `Erreur lors de la création du formulaire: ${error.message}`
                )
              }
            }
            await this.reloadWorkflow()
          } else if (e.entitytype_id == this.getEntityType("SMPL_WORKFLOW_LINE").id) {
            await this.$axios.$post('entities', {
              "entitytype_id": this.getEntityType("SMPL_WORKFLOW_STEP").id,
              "smpl_order": 0,
              "smpl_workflow_step_is_optional": false,
              "smpl_workflow_step_is_batch": false,
              "smpl_workflow_fk": this.workflow.id,
              "smpl_workflow_line_fk": e.id,
              "smpl_event_type_fk": this.getEventTypeByName("Creation")?.id
            })
            await this.$axios.$post('entities', {
              "entitytype_id": this.getEntityType("SMPL_WORKFLOW_STEP").id,
              "smpl_order": 1,
              "smpl_workflow_step_is_optional": false,
              "smpl_workflow_step_is_batch": false,
              "smpl_workflow_fk": this.workflow.id,
              "smpl_workflow_line_fk": e.id,
              "smpl_event_type_fk": this.getEventTypeByName(e.smpl_workflow_line_fk ? "Allocation" : "Collection")?.id
            })
            await this.reloadWorkflow()
          } else if (e.entitytype_id == this.getEntityType("SMPL_WORKFLOW").id) {
            const workflow = e
            await this.getAllWorkflows()
            this.loadWorkflow(workflow.id)
          } else {
            await this.reloadWorkflow()
          }
        } else {
          await this.getAllWorkflows()
          await this.reloadWorkflow()
        }
        this.forms = []
      }
    },

    submit() {
      if (this.loadedForms) {
        this.forms.forEach(form => {
          if (!form.submitted) {
            this.$refs[`formRendering${form.id}`][0].submit();
            form.isValidForm = this.$refs[`formRendering${form.id}`][0].isValidForm
          }
        })
        return true
      }
      return false
    },

    init() {
      const bonhomme = d3.select("#nodeLayer").append("g").attr("id", "bonhomme")
      bonhomme.append("line")
        .attr("x1", 0)
        .attr("y1", 0)
        .attr("x2", 0.78 * this.grid.step[1])
        .attr("y2", 0.78 * this.grid.step[1])
        .attr('stroke', "#000")
        .attr('stroke-width', 3)
        .style("stroke-dasharray", "0, 6")
        .style('stroke-linecap', 'round')

      const addBranchEnter = bonhomme.append("g").attr("class", "addBranch").style("cursor", "pointer")
        .attr("transform", d => "translate(" + (0.78 * this.grid.step[1]) + "," + (0.78 * this.grid.step[1]) + ")scale(1)")
      addBranchEnter.append("circle").attr("r", 10).attr("fill", "white").attr("stroke", "#333333").attr("stroke-width", 1.5)
      addBranchEnter.append("line").attr("x1", -5).attr("x2", 5).attr("stroke", "#333333").attr("stroke-width", 2)
      addBranchEnter.append("line").attr("y1", -5).attr("y2", 5).attr("stroke", "#333333").attr("stroke-width", 2)
      addBranchEnter.on("click", async (e, d) => {
        const childrenLines = this.workflow.lines.filter(line => !line.smpl_workflow_step_fk)
        this.forms = [{
          id: this.lineFormId,
          form: null,
          isValidForm: true,
          defaultEntity: {
            "entitytype_id": this.getEntityType("SMPL_WORKFLOW_LINE").id,
            "smpl_workflow_fk": this.workflow.id,
            "smpl_order": childrenLines.length + 1,
            "smpl_workflow_line_is_kit": true
          }
        }]
        await this.loadForms()
      })

      bonhomme.append("text")
        .attr("id", "bonhommeSubject")
        .attr("fill", "#299D8F")
        .style("text-anchor", "start")
        .style("font-size", "1.4em")
        .style("font-weight", 700)
        .style("cursor", "default")
        .attr('x', -10)
        .attr('y', -0.45 * this.grid.step[1])
        .html("Subject")

      bonhomme.append("circle")
        .attr("cx", 0).attr("cy", 0).attr("r", 22)
        .style("fill", "white")
        .style("stroke", "black")
        .style("stroke-width", 3)

      bonhomme.append("image").attr("xlink:href", this.resources.person)
        .attr("fill", "black")
        .attr("x", -15).attr("y", -15)
        .attr("width", 30)
        .attr("height", 30)
    },

    update() {
      this.steps = []
      this.workflow.lines.forEach(line => {
        line.steps.forEach(step => {
          this.steps.push(step)
        })
      })
      this.setHorizontalPositions()
      this.setVerticalPositions()
      this.aggregateSteps()
      for (var i = 0; i < 15; i++) this.batchAlignment()
      this.removeEmptyRows()
      this.setVerticalPositions()
      for (var i = 0; i < 15; i++) this.batchAlignment()
      this.removeEmptyRows()
      this.setLinks()
      this.enterSteps()
      this.enterLinks()
      this.enterToolbar()
    },

    newWorkflow() {
      const data = {
        name: "",
        batches: [],
        deltas: [],
        steps: [{
          "id": 1000,
          "parent": null,
          "type": "SMPL_SUBJECT",
          "label": "Participant"
        }],
        segments: [{
          "id": 1000,
          "parent": null,
          "steps": [1000]
        }]
      }
      this.workflow = {
        "id": 1000,
        "name": "",
        "data": data
      }
    },

    async reloadWorkflow() {
      this.workflow = await this.dapp.$axios.$get(await this.getRouteURLByName('smpl_load_workflow') + '&workflowId=' + this.workflow.id)
      this.update()
    },

    closeFormToEdit() {
      this.formIdToEdit = null
    },

    fieldNamesByType(typeName) {
      const prefix = 'smpl'
      const fieldNamesByType = {
        SMPL_SUBJECT: {
          formFieldName: 'smpl_workflow_subject_form_id',
          formName: prefix + ' ' + 'New Subject'
        },
        SMPL_CASE: {
          formFieldName: 'smpl_workflow_case_form_id',
          formName: prefix + ' ' + 'New Case',
        },
        SMPL_KIT: {
          formFieldName: 'smpl_workflow_kit_form_id',
          formName: prefix + ' ' + 'New Kit',
        },
        SMPL_COLLECTION: {
          formFieldName: 'smpl_workflow_collection_form_id',
          formName: prefix + ' ' + 'New Collection',
        },
        SMPL_SAMPLE_CREATION: {
          formFieldName: 'smpl_sample_creation_form_id',
          formName: prefix + ' ' + 'New Sample creation prompt',
        },
      }
      return fieldNamesByType[typeName]
    },

    async newForm(typeName, label, entity = null) {
      this.creatingForm = true
      const entityType = this.getEntityType(typeName)
      const response = await this.$axios.$post('/forms', {
        name: label,
        entitytype_id: entityType.id,
        cols: 1
      })
      const formId = response.id
      if (typeName == "SMPL_SUBJECT") {
        this.workflow.smpl_workflow_subject_form_id = formId
        await this.$axios.$put(`/entities/${this.workflow.id}`, {
          "smpl_workflow_subject_form_id": formId
        })
      } else if (typeName == "SMPL_CASE") {
        this.workflow.smpl_workflow_case_form_id = formId
        await this.$axios.$put(`/entities/${this.workflow.id}`, {
          "smpl_workflow_case_form_id": formId
        })
      } else if (typeName == "SMPL_KIT") {
        this.workflow.smpl_workflow_kit_form_id = formId
        await this.$axios.$put(`/entities/${this.workflow.id}`, {
          "smpl_workflow_kit_form_id": formId
        })
      } else if (typeName == "SMPL_COLLECTION") {
        this.workflow.smpl_workflow_collection_form_id = formId
        await this.$axios.$put(`/entities/${this.workflow.id}`, {
          "smpl_workflow_collection_form_id": formId
        })
      } else if (typeName == "SMPL_EVENT") {
        let formFieldName = this.fieldNamesByType[typeName]?.formFieldName || 'smpl_step_form_id'
        entity[formFieldName] = formId
        await this.$axios.$put(`/entities/${entity.id}`, {
          [formFieldName]: formId
        })
      }
      this.creatingForm = false
      await this.reloadWorkflow()
    },

    async loadWorkflow(wfid) {
      if (this.workflows.length > 0) {
        if (!wfid) wfid = this.workflows[0].id
        this.workflow = await this.dapp.$axios.$get(await this.getRouteURLByName('smpl_load_workflow') + '&workflowId=' + wfid)

        for (const line of this.workflow.lines) {
          for (const step of line.steps) {
            const hasFormId = step.hasOwnProperty('smpl_workflow_step_form_id') && step.smpl_workflow_step_form_id !== null && step.smpl_workflow_step_form_id !== undefined
            if (!hasFormId && step.id) {
              try {
                const stepFromDB = await this.$axios.$get(`/entities/${step.id}`)
                if (stepFromDB.smpl_workflow_step_form_id) {
                  this.$set(step, 'smpl_workflow_step_form_id', stepFromDB.smpl_workflow_step_form_id)
                }
              } catch (error) {
                console.error('Error loading step:', error)
              }
            }
          }
        }

        this.update()
        d3.select('svg').transition().call(this.zoom.transform, d3.zoomIdentity.translate(this.grid.origin[0], this.grid.origin[1]).scale(this.zoomScale))

        if (this.workflow.smpl_workflow_is_collection) {
          this.init()
        } else {
          d3.select("#nodeLayer").select("#bonhomme").remove()
        }
      } else {
        this.forms = [{
          id: this.workflowFormId,
          form: null,
          isValidForm: true,
          defaultEntity: {
            "entitytype_id": this.getEntityType("SMPL_WORKFLOW").id
          }
        }]
        await this.loadForms()
      }
    },

    applyCssStyle() {
      const css = '.body { height: 600px !important; }'
      const head = document.head || document.getElementsByTagName('head')[0]
      const style = document.createElement('style')
      head.appendChild(style)
      style.type = 'text/css'
      if (style.styleSheet) {
        style.styleSheet.cssText = css;
      } else {
        style.appendChild(document.createTextNode(css));
      }
      d3.select("#developpedBySBP").append("img").attr("src", this.resources.LOGO_SBP)
    },

    async duplicateForm(formId, name = null) {
      const formToDuplicate = await this.dapp.$axios.$get(`/forms/${formId}`)
      if (name) formToDuplicate.name = name
      const newForm = await this.dapp.$axios.$post("/forms", formToDuplicate)
      for (let i = 0; i < formToDuplicate._displayable_form_contents.length; i++) {
        let displayableformcontent = Object.assign({}, formToDuplicate._displayable_form_contents[i])
        for (const [key, value] of Object.entries(displayableformcontent.details)) {
          if (value == null) {
            delete displayableformcontent.details[key]
          }
        }
        await this.$axios.$post('/forms/' + newForm.id + '/displayableformcontent', displayableformcontent)
      }
      return newForm
    },

    async linkedEvents() {},

    async deleteSamples() {
      const formsToKeep = [1, 2, 3, 4, 28, 32, 13, 19, 47, 52, 57, 63, 275, 246, 263, 258, 267, 249, 1022, 1026, 253, 1343, 1395]
      let forms = await this.dapp.$axios.$get('/forms')
      forms.forEach(async form => {
        if (!formsToKeep.includes(form.id)) await this.dapp.$axios.$delete(`/forms/${form.id}`)
      })
    },
  },

async mounted() {
  const resources = [
    { key: "batch", name: "batch.svg" },
    { key: "person", name: "person.svg" },
    { key: "paw", name: "paw.svg" },
    { key: "branches_hide", name: "branches_hide.png" },
    { key: "branches_show", name: "branches_show.png" },
    { key: "SMPL_logo_2", name: "SMPL_logo_2.png" },
    { key: "LOGO_SBP", name: "LOGO_SBP.svg" },
    { key: "files", name: "files.svg" },
    { key: "files-empty", name: "files-empty.svg" }
  ];

  await this.getResources(resources);
  await this.registerLib({ url: 'https://cdnjs.cloudflare.com/ajax/libs/d3/7.8.5/d3.min.js' });

  this.$nextTick(() => {
    window.addEventListener('resize', this.onResize);
  });

  this.zoom = d3.zoom().scaleExtent([0.25, 2]).on('zoom', this.handleZoom);
  this.initZoom();
  await this.setFormIds();
  await this.getAllWorkflows();
  this.getAllEntityTypes();
  await this.loadWorkflow(null);
  this.applyCssStyle();
  this.onResize();

  window.onkeydown = e => {
    if (e.key == "Alt") {
      d3.selectAll(".removeStep").style("visibility", "visible");
    }
  };

  window.onkeyup = e => {
    if (e.key == "Alt") {
      d3.selectAll(".removeStep").style("visibility", "hidden");
    }
  };
},
  beforeDestroy() {
    window.removeEventListener('resize', this.onResize);
  },
}